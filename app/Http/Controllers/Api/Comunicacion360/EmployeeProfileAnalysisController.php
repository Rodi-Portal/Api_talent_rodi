<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeProfileAnalysisController extends Controller
{
    public function show(Request $request, int $id)
    {
        $employee = DB::connection('portal_main')
            ->table('empleados')
            ->select('id', 'id_portal', 'id_cliente', 'status')
            ->where('id', $id)
            ->first();

        if (! $employee) {
            return response()->json([
                'ok'   => false,
                'code' => 'employee_not_found',
            ], 404);
        }

        $timezone = DB::connection('portal_main')
            ->table('checador_asignaciones as ca')
            ->join('checador_horario_plantillas as chp', 'chp.id', '=', 'ca.id_plantilla_horario')
            ->where('ca.id_portal', $employee->id_portal)
            ->where('ca.id_empleado', $employee->id)
            ->where('ca.activa', 1)
            ->whereDate('ca.fecha_inicio', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('ca.fecha_fin')
                    ->orWhereDate('ca.fecha_fin', '>=', now()->toDateString());
            })
            ->orderByDesc('ca.prioridad')
            ->orderByDesc('ca.id')
            ->value('chp.timezone');

        if (! $timezone) {
            $timezone = config('app.timezone', 'UTC');
        }

        $today = now($timezone)->toDateString();

        $fechaInicio = $request->input(
            'fecha_inicio',
            now($timezone)->subDays(29)->toDateString()
        );

        $fechaFin = $request->input(
            'fecha_fin',
            $today
        );

        $periodType = $request->filled('fecha_inicio') || $request->filled('fecha_fin')
            ? 'custom'
            : 'last_30_days';

        $tasksBaseQuery = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->where('id_portal', $employee->id_portal)
            ->where('empleado_id', $employee->id)
            ->whereNull('deleted_at')
            ->whereDate('fecha_asignacion', '>=', $fechaInicio)
            ->whereDate('fecha_asignacion', '<=', $fechaFin);

        $totalTasks = (clone $tasksBaseQuery)->count();

        $completedTasks = (clone $tasksBaseQuery)
            ->where('estatus', 'completada')
            ->count();

        $pendingTasks = (clone $tasksBaseQuery)
            ->whereIn('estatus', ['pendiente', 'en_proceso'])
            ->count();

        $missingEvidence = (clone $tasksBaseQuery)
            ->where('requiere_evidencia', 1)
            ->where(function ($query) {
                $query->whereNull('tiene_evidencia')
                    ->orWhere('tiene_evidencia', 0);
            })
            ->count();

        $tasksRequiringEvidence = (clone $tasksBaseQuery)
            ->where('requiere_evidencia', 1)
            ->count();

        $complianceScore = $totalTasks > 0
            ? (int) round(($completedTasks / $totalTasks) * 100)
            : null;

        $evidenceScore = $tasksRequiringEvidence > 0
            ? (int) round((($tasksRequiringEvidence - $missingEvidence) / $tasksRequiringEvidence) * 100)
            : null;
        $employeeChecks = DB::connection('portal_main')
            ->table('checadas')
            ->where('id_portal', $employee->id_portal)
            ->where('id_empleado', $employee->id)
            ->whereDate('fecha', '>=', $fechaInicio)
            ->whereDate('fecha', '<=', $fechaFin)
            ->get();

        $employeeAssignments = DB::connection('portal_main')
            ->table('checador_asignaciones as ca')
            ->join('checador_horario_plantillas as chp', 'chp.id', '=', 'ca.id_plantilla_horario')
            ->join('checador_horario_detalles as chd', 'chd.id_plantilla', '=', 'chp.id')
            ->where('ca.id_portal', $employee->id_portal)
            ->where('ca.id_empleado', $employee->id)
            ->where('ca.activa', 1)
            ->get([
                'ca.id',
                'ca.fecha_inicio',
                'ca.fecha_fin',
                'ca.prioridad',
                'chp.timezone',
                'chp.tolerancia_entrada_min',
                'chp.tolerancia_salida_min',
                'chd.dia_semana',
                'chd.labora',
                'chd.hora_entrada',
                'chd.hora_salida',
            ]);
        $lateCount = $employeeChecks
            ->where('tipo', 'in')
            ->where('clase', 'work')
            ->filter(function ($check) use ($employeeAssignments) {
                $checkDate = Carbon::parse($check->fecha)->toDateString();
                $weekday   = (int) Carbon::parse($check->fecha)->dayOfWeek;

                $assignment = $employeeAssignments
                    ->where('dia_semana', $weekday)
                    ->where('labora', 1)
                    ->first(function ($assignment) use ($checkDate) {
                        return $assignment->fecha_inicio <= $checkDate
                            && (
                            $assignment->fecha_fin === null ||
                            $assignment->fecha_fin >= $checkDate
                        );
                    });

                if (! $assignment || ! $assignment->hora_entrada) {
                    return false;
                }

                $scheduledDateTime = Carbon::parse($checkDate . ' ' . $assignment->hora_entrada)
                    ->addMinutes((int) $assignment->tolerancia_entrada_min);

                $checkDateTime = Carbon::parse($check->check_time);

                return $checkDateTime->gt($scheduledDateTime);
            })
            ->pluck('fecha')
            ->unique()
            ->count();

        $employeeEvents = DB::connection('portal_main')
            ->table('calendario_eventos')
            ->where('id_portal', $employee->id_portal)
            ->where('id_empleado', $employee->id)
            ->where('estado', 2)
            ->where('eliminado', 0)
            ->whereDate('inicio', '<=', $fechaFin)
            ->whereDate('fin', '>=', $fechaInicio)
            ->get();
        $employeeExtraEvents = DB::connection('portal_main')
            ->table('checador_evento_detalles as ced')
            ->join('calendario_eventos as ce', 'ce.id', '=', 'ced.id_evento')
            ->where('ce.id_portal', $employee->id_portal)
            ->where('ce.id_empleado', $employee->id)
            ->where('ce.eliminado', 0)
            ->where('ce.estado_aprobacion', 'aprobado')
            ->where('ced.tipo_operativo', 'horas_extra')
            ->where('ced.visible_analisis', 1)
            ->whereDate('ced.fecha', '>=', $fechaInicio)
            ->whereDate('ced.fecha', '<=', $fechaFin)
            ->select([
                'ced.*',
                'ce.descripcion',
                'ce.estado_aprobacion',
                'ce.origen_evento',
            ])
            ->get();

        $extraMinutesApproved = (int) $employeeExtraEvents->sum('minutos_aprobados');
        $extraMinutesPayable  = (int) $employeeExtraEvents->sum('minutos_pagables');
        $extraEventsCount     = $employeeExtraEvents->count();
        $workCheckDates       = $employeeChecks
            ->where('tipo', 'in')
            ->where('clase', 'work')
            ->pluck('fecha')
            ->map(fn($fecha) => Carbon::parse($fecha)->toDateString())
            ->unique()
            ->flip();

        $scheduledWorkDays = 0;
        $justifiedDays     = 0;
        $workedDays        = 0;

        $justifiedEventTypeIds = [1, 2, 3, 10];

        foreach (CarbonPeriod::create($fechaInicio, $fechaFin) as $date) {
            $currentDate = $date->toDateString();
            $weekday     = (int) $date->dayOfWeek; // 0 = domingo, 6 = sábado

            $isScheduledWorkDay = $employeeAssignments
                ->where('dia_semana', $weekday)
                ->where('labora', 1)
                ->contains(function ($assignment) use ($currentDate) {
                    return $assignment->fecha_inicio <= $currentDate
                        && (
                        $assignment->fecha_fin === null ||
                        $assignment->fecha_fin >= $currentDate
                    );
                });

            if (! $isScheduledWorkDay) {
                continue;
            }

            $scheduledWorkDays++;

            $isJustifiedDay = $employeeEvents
                ->whereIn('id_tipo', $justifiedEventTypeIds)
                ->contains(function ($event) use ($currentDate) {
                    return $event->inicio <= $currentDate
                    && $event->fin >= $currentDate;
                });

            if ($isJustifiedDay) {
                $justifiedDays++;
                continue;
            }

            if ($workCheckDates->has($currentDate)) {
                $workedDays++;
            }
        }

        $dailySummary = [];

        foreach (CarbonPeriod::create($fechaInicio, $fechaFin) as $date) {
            $currentDate = $date->toDateString();
            $weekday     = (int) $date->dayOfWeek;

            $isScheduled = $employeeAssignments
                ->where('dia_semana', $weekday)
                ->where('labora', 1)
                ->contains(function ($assignment) use ($currentDate) {
                    return $assignment->fecha_inicio <= $currentDate
                        && (
                        $assignment->fecha_fin === null ||
                        $assignment->fecha_fin >= $currentDate
                    );
                });

            $isJustified = $employeeEvents
                ->whereIn('id_tipo', $justifiedEventTypeIds)
                ->contains(function ($event) use ($currentDate) {
                    return $event->inicio <= $currentDate
                    && $event->fin >= $currentDate;
                });

            $dayChecks = $employeeChecks->where('fecha', $currentDate);

            $workIns = $dayChecks
                ->where('tipo', 'in')
                ->where('clase', 'work');

            $lateIns = $workIns->filter(function ($check) use ($employeeAssignments, $currentDate, $weekday) {
                $assignment = $employeeAssignments
                    ->where('dia_semana', $weekday)
                    ->where('labora', 1)
                    ->first(function ($assignment) use ($currentDate) {
                        return $assignment->fecha_inicio <= $currentDate
                            && (
                            $assignment->fecha_fin === null ||
                            $assignment->fecha_fin >= $currentDate
                        );
                    });

                if (! $assignment || ! $assignment->hora_entrada) {
                    return false;
                }

                $scheduledDateTime = Carbon::parse($currentDate . ' ' . $assignment->hora_entrada)
                    ->addMinutes((int) $assignment->tolerancia_entrada_min);

                return Carbon::parse($check->check_time)->gt($scheduledDateTime);
            });

            $warningCount  = $dayChecks->where('estatus_validacion', 'advertida')->count();
            $rejectedCount = $dayChecks->where('estatus_validacion', 'rechazada')->count();
            $checkCount    = $dayChecks->count();

            $worked                  = $workIns->count() > 0;
            $absent                  = $isScheduled && ! $isJustified && ! $worked;
            $dayExtraEvents          = $employeeExtraEvents->where('fecha', $currentDate);
            $dayExtraMinutesApproved = (int) $dayExtraEvents->sum('minutos_aprobados');
            $dayExtraMinutesPayable  = (int) $dayExtraEvents->sum('minutos_pagables');
            $dailySummary[]          = [
                'date'                   => $currentDate,
                'weekday'                => $weekday,
                'scheduled'              => $isScheduled,
                'justified'              => $isJustified,
                'worked'                 => $worked,
                'absent'                 => $absent,
                'extra_work'             => ! $isScheduled && $worked,
                'check_count'            => $checkCount,
                'late_count'             => $lateIns->pluck('fecha')->unique()->count(),
                'warning_count'          => $warningCount,
                'rejected_count'         => $rejectedCount,
                'extra_events_count'     => $dayExtraEvents->count(),
                'extra_minutes_approved' => $dayExtraMinutesApproved,
                'extra_minutes_payable'  => $dayExtraMinutesPayable,
            ];
        }
        $expectedWorkDays            = max($scheduledWorkDays - $justifiedDays, 0);
        $hasOperationalConfiguration = $scheduledWorkDays > 0;
        $absencesCount               = max($expectedWorkDays - $workedDays, 0);

        $attendanceScore = $expectedWorkDays > 0
            ? (int) round(($workedDays / $expectedWorkDays) * 100)
            : null;

        $punctualityScore = $workedDays > 0
            ? (int) round((($workedDays - $lateCount) / $workedDays) * 100)
            : null;
        $riskLevel = 'low';

        if (
            $absencesCount >= 3 ||
            $lateCount >= 5 ||
            $pendingTasks >= 10 ||
            $missingEvidence >= 5 ||
            ($attendanceScore !== null && $attendanceScore < 70) ||
            ($complianceScore !== null && $complianceScore < 60)
        ) {
            $riskLevel = 'high';
        } elseif (
            $absencesCount >= 1 ||
            $lateCount >= 2 ||
            $pendingTasks >= 3 ||
            $missingEvidence >= 2 ||
            ($attendanceScore !== null && $attendanceScore < 85) ||
            ($complianceScore !== null && $complianceScore < 80)
        ) {
            $riskLevel = 'medium';
        }
        $timeline = collect();
        $insights = [];
        if (! $hasOperationalConfiguration) {
            $operationalStatus = 'unconfigured';
        } else {
            $timeline = $employeeChecks
                ->sortByDesc('check_time')
                ->take(12)
                ->map(function ($item) use ($employeeAssignments) {
                    $type = 'check';

                    if ($item->tipo === 'in' && $item->clase === 'work') {
                        $type = 'work_check_in';
                    } elseif ($item->tipo === 'out' && $item->clase === 'work') {
                        $type = 'work_check_out';
                    } elseif ($item->tipo === 'out' && $item->clase === 'meal') {
                        $type = 'meal_start';
                    } elseif ($item->tipo === 'in' && $item->clase === 'meal') {
                        $type = 'meal_return';
                    } elseif ($item->tipo === 'out' && $item->clase === 'break') {
                        $type = 'break_start';
                    } elseif ($item->tipo === 'in' && $item->clase === 'break') {
                        $type = 'break_return';
                    }

                    $severity = 'low';
                    $params   = new \stdClass();

                    $checkDate = Carbon::parse($item->fecha)->toDateString();
                    $weekday   = (int) Carbon::parse($item->fecha)->dayOfWeek;
                    if ($item->tipo === 'in' && $item->clase === 'work') {
                        $assignment = $employeeAssignments
                            ->where('dia_semana', $weekday)
                            ->where('labora', 1)
                            ->first(function ($assignment) use ($checkDate) {
                                return $assignment->fecha_inicio <= $checkDate
                                    && (
                                    $assignment->fecha_fin === null ||
                                    $assignment->fecha_fin >= $checkDate
                                );
                            });

                        if ($assignment && $assignment->hora_entrada) {
                            $scheduledDateTime = Carbon::parse($checkDate . ' ' . $assignment->hora_entrada)
                                ->addMinutes((int) $assignment->tolerancia_entrada_min);

                            $checkDateTime = Carbon::parse($item->check_time);

                            if ($checkDateTime->gt($scheduledDateTime)) {
                                $severity = 'medium';
                                $type     = 'late_check_in';
                                $params   = [
                                    'minutes_late' => $scheduledDateTime->diffInMinutes($checkDateTime),
                                ];
                            }
                        }
                    }

                    if ($item->estatus_validacion === 'advertida') {
                        $severity = 'medium';
                    }

                    if ($item->estatus_validacion === 'rechazada') {
                        $severity = 'high';
                    }

                    return [
                        'type'     => $type,
                        'severity' => $severity,
                        'datetime' => $item->check_time,
                        'date'     => $item->fecha,
                        'params'   => $params,
                        'meta'     => [
                            'validation_status' => $item->estatus_validacion,
                            'origin'            => $item->origen,
                        ],
                    ];
                })
                ->values();
            $extraTimeline = $employeeExtraEvents
                ->map(function ($item) {
                    return [
                        'type'     => 'overtime_approved',
                        'severity' => 'medium',
                        'datetime' => $item->fecha . ' ' . ($item->hora_inicio ?? '00:00:00'),
                        'date'     => $item->fecha,
                        'params'   => [
                            'minutes_approved' => (int) $item->minutos_aprobados,
                            'minutes_payable'  => (int) $item->minutos_pagables,
                        ],
                        'meta'     => [
                            'approval_mode' => $item->modo_aprobacion,
                            'origin'        => $item->origen_evento,
                        ],
                    ];
                });

            $timeline = $timeline
                ->merge($extraTimeline)
                ->sortByDesc('datetime')
                ->take(12)
                ->values();
            $insights = [];

            if ($attendanceScore !== null && $attendanceScore < 80) {
                $insights[] = [
                    'code'     => 'low_attendance',
                    'severity' => $attendanceScore < 70 ? 'high' : 'medium',
                    'params'   => [
                        'attendance' => $attendanceScore,
                        'absences'   => $absencesCount,
                    ],
                ];
            }

            if ($lateCount >= 2) {
                $insights[] = [
                    'code'     => 'recurrent_lateness',
                    'severity' => $lateCount >= 5 ? 'high' : 'medium',
                    'params'   => [
                        'late_count' => $lateCount,
                    ],
                ];
            }

            if ($pendingTasks >= 3) {
                $insights[] = [
                    'code'     => 'pending_tasks',
                    'severity' => $pendingTasks >= 10 ? 'high' : 'medium',
                    'params'   => [
                        'pending_tasks' => $pendingTasks,
                    ],
                ];
            }

            if ($missingEvidence >= 2) {
                $insights[] = [
                    'code'     => 'missing_evidence',
                    'severity' => $missingEvidence >= 5 ? 'high' : 'medium',
                    'params'   => [
                        'missing_evidence' => $missingEvidence,
                    ],
                ];
            }

            if ($workedDays === 0 && $expectedWorkDays > 0) {
                $insights[] = [
                    'code'     => 'no_activity',
                    'severity' => 'high',
                    'params'   => [
                        'expected_work_days' => $expectedWorkDays,
                    ],
                ];
            }
            $operationalStatus = match ($riskLevel) {
                'high'   => 'critical',
                'medium' => 'observed',
                default  => 'normal',
            };
        }
        return response()->json([
            'ok'   => true,
            'data' => [
                'meta'          => [
                    'period' => [
                        'type'         => $periodType,
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin'    => $fechaFin,
                        'timezone'     => $timezone,
                    ],
                ],

                'employee_id'   => (int) $employee->id,
                'status'        => $operationalStatus,
                'risk_level'    => $riskLevel,

                'scores'        => [
                    'punctuality'  => $punctualityScore,
                    'productivity' => null,
                    'attendance'   => $attendanceScore,
                    'compliance'   => $complianceScore,
                    'evidence'     => $evidenceScore,
                ],

                'kpis'          => [
                    'late_count'             => $lateCount,
                    'absences_count'         => $absencesCount,
                    'completed_tasks'        => $completedTasks,
                    'pending_tasks'          => $pendingTasks,
                    'missing_evidence'       => $missingEvidence,
                    'scheduled_work_days'    => $scheduledWorkDays,
                    'justified_days'         => $justifiedDays,
                    'worked_days'            => $workedDays,
                    'extra_events_count'     => $extraEventsCount,
                    'extra_minutes_approved' => $extraMinutesApproved,
                    'extra_minutes_payable'  => $extraMinutesPayable,
                ],

                'insights'      => $insights,

                'trends'        => [
                    'current_week'   => [],
                    'previous_week'  => [],
                    'current_month'  => [],
                    'previous_month' => [],
                ],

                'timeline'      => $timeline,
                'daily_summary' => $dailySummary,
            ],
        ]);
    }
}
