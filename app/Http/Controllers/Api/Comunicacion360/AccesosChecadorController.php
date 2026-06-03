<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\Checada;
use App\Models\Comunicacion360\Checador\ChecadorAsignacion;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AccesosChecadorController extends Controller
{
    public function checadasDia(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');
        $fecha    = $request->input('fecha', now()->toDateString());

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $checadas = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->whereDate('fecha', $fecha)
            ->orderBy('check_time')
            ->get();

        $primeraEntrada = $checadas
            ->where('tipo', 'in')
            ->first();

        $ultimaChecada = $checadas->last();

        return response()->json([
            'ok'   => true,
            'data' => [
                'fecha'           => $fecha,
                'total'           => $checadas->count(),
                'estado_actual'   => $ultimaChecada
                    ? $ultimaChecada->tipo . '/' . $ultimaChecada->clase
                    : 'sin_checada',
                'primera_entrada' => $primeraEntrada?->check_time?->format('Y-m-d H:i:s'),
                'ultima_checada'  => $ultimaChecada?->check_time?->format('Y-m-d H:i:s'),
                'items'           => $checadas->map(function ($item) {
                    return [
                        'id'                 => $item->id,
                        'fecha'              => $item->fecha?->format('Y-m-d'),
                        'check_time'         => $item->check_time?->format('Y-m-d H:i:s'),
                        'hora'               => $item->check_time?->format('H:i'),
                        'tipo'               => $item->tipo,
                        'clase'              => $item->clase,
                        'origen'             => $item->origen,
                        'metodo_validacion'  => $item->metodo_validacion,
                        'estatus_validacion' => $item->estatus_validacion,
                        'id_ubicacion'       => $item->id_ubicacion,
                        'distancia_metros'   => $item->distancia_metros,
                        'precision_metros'   => $item->precision_metros,
                        'observacion'        => $item->observacion,
                        'tiene_evidencia'    => ! empty($item->evidencia_foto),
                    ];
                })->values(),
            ],
        ]);
    }

    public function metricasDia(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');
        $fecha    = $request->input('fecha', now()->toDateString());

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $fechaCarbon = Carbon::parse($fecha);
        $diaSemana   = (int) $fechaCarbon->dayOfWeek; // 0 domingo, 1 lunes...

        $asignacion = ChecadorAsignacion::query()
            ->with([
                'horarioPlantilla.detalles' => function ($query) use ($diaSemana) {
                    $query->where('dia_semana', $diaSemana)
                        ->orderBy('orden');
                },
            ])
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->where(function ($query) use ($fecha) {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fecha);
            })
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        if (! $asignacion || ! $asignacion->horarioPlantilla) {
            return response()->json([
                'ok'   => true,
                'data' => [
                    'fecha'            => $fecha,
                    'tiene_horario'    => false,
                    'estado_operativo' => 'sin_horario',
                    'metricas'         => null,
                    'alertas'          => [
                        [
                            'tipo'    => 'sin_horario',
                            'nivel'   => 'warning',
                            'mensaje' => 'El colaborador no tiene un horario activo asignado para esta fecha.',
                        ],
                    ],
                ],
            ]);
        }

        $plantillaHorario = $asignacion->horarioPlantilla;
        $detalleHorario   = $plantillaHorario->detalles->first();

        $checadas = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->whereDate('fecha', $fecha)
            ->orderBy('check_time')
            ->get();

        $alertas = [];
        if ($detalleHorario && $detalleHorario->labora && $checadas->count() === 0) {
            $inicioProgramado = Carbon::parse($fecha . ' ' . $detalleHorario->hora_entrada, $plantillaHorario->timezone);
            $finProgramado    = Carbon::parse($fecha . ' ' . $detalleHorario->hora_salida, $plantillaHorario->timezone);

            if ($finProgramado->lessThanOrEqualTo($inicioProgramado)) {
                $finProgramado->addDay();
            }

            $minutosProgramados = $inicioProgramado->diffInMinutes($finProgramado);

            return response()->json([
                'ok'   => true,
                'data' => [
                    'fecha'            => $fecha,
                    'tiene_horario'    => true,
                    'estado_operativo' => 'ausencia',
                    'horario'          => [
                        'id_asignacion'          => $asignacion->id,
                        'id_plantilla'           => $plantillaHorario->id,
                        'nombre'                 => $plantillaHorario->nombre,
                        'timezone'               => $plantillaHorario->timezone,
                        'dia_semana'             => $diaSemana,
                        'labora'                 => true,
                        'hora_entrada'           => $detalleHorario->hora_entrada,
                        'hora_salida'            => $detalleHorario->hora_salida,
                        'tolerancia_entrada_min' => $plantillaHorario->tolerancia_entrada_min,
                        'tolerancia_salida_min'  => $plantillaHorario->tolerancia_salida_min,
                        'permite_descanso'       => $plantillaHorario->permite_descanso,
                    ],
                    'metricas'         => [
                        'minutos_programados'       => $minutosProgramados,
                        'minutos_trabajados'        => 0,
                        'minutos_retardo'           => 0,
                        'minutos_salida_anticipada' => 0,
                        'minutos_comida'            => 0,
                        'minutos_break'             => 0,
                        'puntualidad_pct'           => 0,
                        'productividad_pct'         => 0,
                    ],
                    'alertas'          => [
                        [
                            'tipo'    => 'ausencia',
                            'nivel'   => 'danger',
                            'mensaje' => 'Día laborable sin checadas registradas.',
                        ],
                    ],
                ],
            ]);
        }

        if (! $detalleHorario || ! $detalleHorario->labora) {
            if ($checadas->count() > 0) {
                $alertas[] = [
                    'tipo'    => 'dia_no_laborable_con_checadas',
                    'nivel'   => 'warning',
                    'mensaje' => 'El colaborador registró checadas en un día no laborable.',
                ];
            }

            return response()->json([
                'ok'   => true,
                'data' => [
                    'fecha'            => $fecha,
                    'tiene_horario'    => true,
                    'estado_operativo' => $checadas->count() > 0
                        ? 'dia_no_laborable_con_checadas'
                        : 'dia_no_laborable',
                    'horario'          => [
                        'id_plantilla'           => $plantillaHorario->id,
                        'nombre'                 => $plantillaHorario->nombre,
                        'timezone'               => $plantillaHorario->timezone,
                        'dia_semana'             => $diaSemana,
                        'labora'                 => false,
                        'hora_entrada'           => null,
                        'hora_salida'            => null,
                        'tolerancia_entrada_min' => $plantillaHorario->tolerancia_entrada_min,
                        'tolerancia_salida_min'  => $plantillaHorario->tolerancia_salida_min,
                        'permite_descanso'       => $plantillaHorario->permite_descanso,
                    ],
                    'metricas'         => [
                        'minutos_programados'       => 0,
                        'minutos_trabajados'        => 0,
                        'minutos_retardo'           => 0,
                        'minutos_salida_anticipada' => 0,
                        'minutos_comida'            => 0,
                        'minutos_break'             => 0,
                        'puntualidad_pct'           => null,
                        'productividad_pct'         => null,
                    ],
                    'alertas'          => $alertas,
                ],
            ]);
        }

        $inicioProgramado = Carbon::parse($fecha . ' ' . $detalleHorario->hora_entrada, $plantillaHorario->timezone);
        $finProgramado    = Carbon::parse($fecha . ' ' . $detalleHorario->hora_salida, $plantillaHorario->timezone);

        if ($finProgramado->lessThanOrEqualTo($inicioProgramado)) {
            $finProgramado->addDay();
        }

        $entradaLimite = $inicioProgramado->copy()->addMinutes((int) $plantillaHorario->tolerancia_entrada_min);
        $salidaLimite  = $finProgramado->copy()->subMinutes((int) $plantillaHorario->tolerancia_salida_min);

        $primeraEntradaTrabajo = $checadas
            ->where('tipo', 'in')
            ->where('clase', 'work')
            ->first();

        $ultimaSalidaTrabajo = $checadas
            ->where('tipo', 'out')
            ->where('clase', 'work')
            ->last();

        $minutosProgramados      = $inicioProgramado->diffInMinutes($finProgramado);
        $minutosRetardo          = 0;
        $minutosSalidaAnticipada = 0;

        if (! $primeraEntradaTrabajo) {
            $alertas[] = [
                'tipo'    => 'sin_entrada',
                'nivel'   => 'danger',
                'mensaje' => 'No existe entrada laboral registrada.',
            ];
        } else {
            $entradaReal = Carbon::parse($primeraEntradaTrabajo->check_time, $plantillaHorario->timezone);

            if ($entradaReal->greaterThan($entradaLimite)) {
                $minutosRetardo = $entradaLimite->diffInMinutes($entradaReal);

                $alertas[] = [
                    'tipo'    => 'retardo',
                    'nivel'   => $minutosRetardo >= 30 ? 'danger' : 'warning',
                    'mensaje' => "Entrada posterior a la tolerancia por {$minutosRetardo} minutos.",
                ];
            }
        }

        if (! $ultimaSalidaTrabajo) {
            $alertas[] = [
                'tipo'    => 'sin_salida',
                'nivel'   => 'danger',
                'mensaje' => 'No existe salida laboral registrada.',
            ];
        } else {
            $salidaReal = Carbon::parse($ultimaSalidaTrabajo->check_time, $plantillaHorario->timezone);

            if ($salidaReal->lessThan($salidaLimite)) {
                $minutosSalidaAnticipada = $salidaReal->diffInMinutes($salidaLimite);

                $alertas[] = [
                    'tipo'    => 'salida_anticipada',
                    'nivel'   => $minutosSalidaAnticipada >= 30 ? 'danger' : 'warning',
                    'mensaje' => "Salida antes de la tolerancia por {$minutosSalidaAnticipada} minutos.",
                ];
            }
        }

        $minutosComida  = $this->calcularMinutosPorClase($checadas, 'meal', $plantillaHorario->timezone);
        $minutosBreak   = $this->calcularMinutosPorClase($checadas, 'break', $plantillaHorario->timezone);
        $minutosTrabajo = $this->calcularMinutosPorClase($checadas, 'work', $plantillaHorario->timezone);

        if ($minutosTrabajo === 0 && $checadas->count() > 0) {
            $alertas[] = [
                'tipo'    => 'jornada_incompleta',
                'nivel'   => 'warning',
                'mensaje' => 'Hay checadas registradas, pero no existe un par completo entrada/salida laboral.',
            ];
        }

        $puntualidadPct = $minutosProgramados > 0
            ? max(0, round((($minutosProgramados - $minutosRetardo) / $minutosProgramados) * 100, 2))
            : null;

        $productividadPct = $minutosProgramados > 0
            ? min(100, round(($minutosTrabajo / $minutosProgramados) * 100, 2))
            : null;

        $estadoOperativo = 'normal';

        if (collect($alertas)->where('nivel', 'danger')->count() > 0) {
            $estadoOperativo = 'critico';
        } elseif (count($alertas) > 0) {
            $estadoOperativo = 'observado';
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'fecha'            => $fecha,
                'tiene_horario'    => true,
                'estado_operativo' => $estadoOperativo,
                'horario'          => [
                    'id_asignacion'          => $asignacion->id,
                    'id_plantilla'           => $plantillaHorario->id,
                    'nombre'                 => $plantillaHorario->nombre,
                    'timezone'               => $plantillaHorario->timezone,
                    'dia_semana'             => $diaSemana,
                    'labora'                 => true,
                    'hora_entrada'           => $detalleHorario->hora_entrada,
                    'hora_salida'            => $detalleHorario->hora_salida,
                    'tolerancia_entrada_min' => $plantillaHorario->tolerancia_entrada_min,
                    'tolerancia_salida_min'  => $plantillaHorario->tolerancia_salida_min,
                    'permite_descanso'       => $plantillaHorario->permite_descanso,
                ],
                'metricas'         => [
                    'minutos_programados'       => $minutosProgramados,
                    'minutos_trabajados'        => $minutosTrabajo,
                    'minutos_retardo'           => $minutosRetardo,
                    'minutos_salida_anticipada' => $minutosSalidaAnticipada,
                    'minutos_comida'            => $minutosComida,
                    'minutos_break'             => $minutosBreak,
                    'puntualidad_pct'           => $puntualidadPct,
                    'productividad_pct'         => $productividadPct,
                ],
                'alertas'          => $alertas,
            ],
        ]);
    }
    public function metricasOperativas(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');

        $fechaInicio = $request->input('fecha_inicio', now()->toDateString());
        $fechaFin    = $request->input('fecha_fin', now()->toDateString());

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin    = Carbon::parse($fechaFin)->startOfDay();

        if ($fin->lessThan($inicio)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La fecha fin no puede ser menor que la fecha inicio.',
            ], 422);
        }

        $checadasPorFecha = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('check_time')
            ->get()
            ->groupBy(function ($item) {
                return $item->fecha?->format('Y-m-d');
            });

        $minutosProgramados      = 0;
        $minutosTrabajados       = 0;
        $minutosRetardo          = 0;
        $minutosSalidaAnticipada = 0;
        $minutosComida           = 0;
        $minutosBreak            = 0;

        $diasCalendario              = 0;
        $diasLaborables              = 0;
        $diasTrabajados              = 0;
        $diasSinChecadas             = 0;
        $diasSinHorario              = 0;
        $diasNoLaborablesConChecadas = 0;

        $alertas = [];

        $cursor = $inicio->copy();

        while ($cursor->lessThanOrEqualTo($fin)) {
            $fecha     = $cursor->toDateString();
            $diaSemana = (int) $cursor->dayOfWeek;
            $diasCalendario++;

            $asignacion = ChecadorAsignacion::query()
                ->with([
                    'horarioPlantilla.detalles' => function ($query) use ($diaSemana) {
                        $query->where('dia_semana', $diaSemana)
                            ->orderBy('orden');
                    },
                ])
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $id)
                ->where('activa', 1)
                ->whereDate('fecha_inicio', '<=', $fecha)
                ->where(function ($query) use ($fecha) {
                    $query->whereNull('fecha_fin')
                        ->orWhereDate('fecha_fin', '>=', $fecha);
                })
                ->orderByDesc('prioridad')
                ->orderByDesc('id')
                ->first();

            $checadasDia = $checadasPorFecha->get($fecha, collect());

            if (! $asignacion || ! $asignacion->horarioPlantilla) {
                $diasSinHorario++;

                if ($checadasDia->count() > 0) {
                    $diasTrabajados++;
                }

                $cursor->addDay();
                continue;
            }

            $plantillaHorario = $asignacion->horarioPlantilla;
            $detalleHorario   = $plantillaHorario->detalles->first();

            if (! $detalleHorario || ! $detalleHorario->labora) {
                if ($checadasDia->count() > 0) {
                    $diasNoLaborablesConChecadas++;
                    $diasTrabajados++;
                }

                $cursor->addDay();
                continue;
            }

            $diasLaborables++;

            $inicioProgramado = Carbon::parse(
                $fecha . ' ' . $detalleHorario->hora_entrada,
                $plantillaHorario->timezone
            );

            $finProgramado = Carbon::parse(
                $fecha . ' ' . $detalleHorario->hora_salida,
                $plantillaHorario->timezone
            );

            if ($finProgramado->lessThanOrEqualTo($inicioProgramado)) {
                $finProgramado->addDay();
            }

            $minutosProgramadosDia = $inicioProgramado->diffInMinutes($finProgramado);
            $minutosProgramados    += $minutosProgramadosDia;

            if ($checadasDia->count() === 0) {
                $diasSinChecadas++;
                $cursor->addDay();
                continue;
            }

            $diasTrabajados++;

            $entradaLimite = $inicioProgramado->copy()
                ->addMinutes((int) $plantillaHorario->tolerancia_entrada_min);

            $salidaLimite = $finProgramado->copy()
                ->subMinutes((int) $plantillaHorario->tolerancia_salida_min);

            $primeraEntradaTrabajo = $checadasDia
                ->where('tipo', 'in')
                ->where('clase', 'work')
                ->first();

            $ultimaSalidaTrabajo  = $checadasDia
                ->where('tipo', 'out')
                ->where('clase', 'work')
                ->last();

            if ($primeraEntradaTrabajo) {
                $entradaReal = Carbon::parse(
                    $primeraEntradaTrabajo->check_time,
                    $plantillaHorario->timezone
                );

                if ($entradaReal->greaterThan($entradaLimite)) {
                    $minutosRetardo += $entradaLimite->diffInMinutes($entradaReal);
                }
            } else {
                $alertas[] = [
                    'tipo'   => 'sin_entrada',
                    'nivel'  => 'danger',
                    'params' => [
                        'date' => $fecha,
                    ],
                ];
            }

            if ($ultimaSalidaTrabajo) {
                $salidaReal = Carbon::parse(
                    $ultimaSalidaTrabajo->check_time,
                    $plantillaHorario->timezone
                );

                if ($salidaReal->lessThan($salidaLimite)) {
                    $minutosSalidaAnticipada += $salidaReal->diffInMinutes($salidaLimite);
                }
            } else {
                $alertas[] = [
                    'tipo'   => 'sin_salida',
                    'nivel'  => 'warning',
                    'params' => [
                        'date' => $fecha,
                    ],
                ];
            }

            $minutosTrabajados += $this->calcularMinutosPorClase(
                $checadasDia,
                'work',
                $plantillaHorario->timezone
            );

            $minutosComida += $this->calcularMinutosPorClase(
                $checadasDia,
                'meal',
                $plantillaHorario->timezone
            );

            $minutosBreak += $this->calcularMinutosPorClase(
                $checadasDia,
                'break',
                $plantillaHorario->timezone
            );

            $cursor->addDay();
        }

        if ($diasSinChecadas > 0) {
            $alertas[] = [
                'tipo'   => 'ausencias_periodo',
                'nivel'  => $diasSinChecadas >= 3 ? 'danger' : 'warning',
                'params' => [
                    'count' => $diasSinChecadas,
                ],
            ];
        }

        if ($diasNoLaborablesConChecadas > 0) {
            $alertas[] = [
                'tipo'   => 'dias_no_laborables_con_checadas',
                'nivel'  => 'warning',
                'params' => [
                    'count' => $diasNoLaborablesConChecadas,
                ],
            ];
        }

        if ($diasSinHorario > 0) {
            $alertas[] = [
                'tipo'   => 'dias_sin_horario',
                'nivel'  => 'warning',
                'params' => [
                    'count' => $diasSinHorario,
                ],
            ];
        }

        $puntualidadPct = $minutosProgramados > 0
            ? max(0, round((($minutosProgramados - $minutosRetardo) / $minutosProgramados) * 100, 2))
            : null;

        $productividadPct = $minutosProgramados > 0
            ? min(100, round(($minutosTrabajados / $minutosProgramados) * 100, 2))
            : null;

        $estadoOperativo = 'normal';

        if (collect($alertas)->where('nivel', 'danger')->count() > 0) {
            $estadoOperativo = 'critico';
        } elseif (count($alertas) > 0) {
            $estadoOperativo = 'observado';
        }

        return response()->json([
            'ok'   => true,
            'data' => [
                'periodo'          => [
                    'fecha_inicio' => $inicio->toDateString(),
                    'fecha_fin'    => $fin->toDateString(),
                    'es_un_dia'    => $inicio->isSameDay($fin),
                ],
                'estado_operativo' => $estadoOperativo,
                'metricas'         => [
                    'dias_calendario'             => $diasCalendario,
                    'dias_laborables'             => $diasLaborables,
                    'dias_trabajados'             => $diasTrabajados,
                    'dias_sin_checadas'           => $diasSinChecadas,
                    'dias_sin_horario'            => $diasSinHorario,
                    'dias_no_laborables_checadas' => $diasNoLaborablesConChecadas,
                    'minutos_programados'         => $minutosProgramados,
                    'minutos_trabajados'          => $minutosTrabajados,
                    'minutos_retardo'             => $minutosRetardo,
                    'minutos_salida_anticipada'   => $minutosSalidaAnticipada,
                    'minutos_comida'              => $minutosComida,
                    'minutos_break'               => $minutosBreak,
                    'puntualidad_pct'             => $puntualidadPct,
                    'productividad_pct'           => $productividadPct,
                ],
                'alertas'          => $alertas,
            ],
        ]);
    }

    public function historialChecadas(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');

        $fechaInicio = $request->input(
            'fecha_inicio',
            now()->subDays(7)->toDateString()
        );

        $fechaFin = $request->input(
            'fecha_fin',
            now()->toDateString()
        );

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $checadas = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->orderByDesc('fecha')
            ->orderBy('check_time')
            ->get();

        $dias = $checadas
            ->groupBy(function ($item) {
                return $item->fecha?->format('Y-m-d');
            })
            ->map(function ($items, $fecha) {
                $primeraEntrada = $items
                    ->where('tipo', 'in')
                    ->where('clase', 'work')
                    ->first();

                $ultimaChecada = $items->last();

                return [
                    'fecha'            => $fecha,
                    'total_checadas'   => $items->count(),
                    'primera_entrada'  => $primeraEntrada?->check_time?->format('Y-m-d H:i:s'),
                    'ultima_checada'   => $ultimaChecada?->check_time?->format('Y-m-d H:i:s'),
                    'tiene_evidencias' => $items->contains(function ($item) {
                        return ! empty($item->evidencia_foto);
                    }),
                ];
            })
            ->values();

        return response()->json([
            'ok'   => true,
            'data' => $dias,
        ]);
    }
    private function calcularMinutosPorClase($checadas, string $clase, string $timezone): int
    {
        $eventos = $checadas
            ->where('clase', $clase)
            ->values();

        $inicio = null;
        $total  = 0;

        foreach ($eventos as $evento) {
            if ($evento->tipo === 'in') {
                $inicio = Carbon::parse($evento->check_time, $timezone);
                continue;
            }

            if ($evento->tipo === 'out' && $inicio) {
                $fin = Carbon::parse($evento->check_time, $timezone);

                if ($fin->greaterThan($inicio)) {
                    $total += $inicio->diffInMinutes($fin);
                }

                $inicio = null;
            }
        }

        return $total;
    }

    public function evidenciaChecada(Request $request, $id, $idChecada)
    {
        $idPortal = $request->input('id_portal');

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $checada = Checada::query()
            ->where('id', $idChecada)
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->first();

        if (! $checada || empty($checada->evidencia_foto)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Evidencia no encontrada.',
            ], 404);
        }

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        $relativePath = ltrim($checada->evidencia_foto, '/\\');

        $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

        if (! file_exists($fullPath)) {
            return response()->json([
                'ok'      => false,
                'message' => 'El archivo de evidencia no existe en el servidor.',
            ], 404);
        }

        $mime = mime_content_type($fullPath);

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'       => $checada->id,
                'mime'     => $mime,
                'filename' => basename($fullPath),
                'base64'   => 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath)),
            ],
        ]);
    }
}
