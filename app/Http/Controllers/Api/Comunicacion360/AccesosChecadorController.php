<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\Checada;
use App\Models\Comunicacion360\Checador\ChecadorAsignacion;
use App\Services\Checador\JornadaCalculoService;
use App\Services\Checador\VentanaOperativaService;
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

        $ventanaOperativa = null;

        if ($detalleHorario && $detalleHorario->labora) {
            $ventanaOperativa = app(VentanaOperativaService::class)->resolver(
                $fecha,
                $detalleHorario,
                $plantillaHorario
            );

            $checadas = Checada::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $id)
                ->whereBetween('check_time', [
                    $ventanaOperativa['ventana']['inicio'],
                    $ventanaOperativa['ventana']['fin'],
                ])
                ->orderBy('check_time')
                ->get();
        } else {
            $checadas = Checada::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $id)
                ->whereDate('fecha', $fecha)
                ->orderBy('check_time')
                ->get();
        }

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

                        'dispositivo'        => $item->dispositivo,
                        'device_info'        => $item->device_info,
                        'metadata'           => $item->metadata,

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
        $alertas  = [];
        $idPortal = $request->input('id_portal');
        $fecha    = $request->input('fecha', now()->toDateString());

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $fechaCarbon = Carbon::parse($fecha);
        $diaSemana   = (int) $fechaCarbon->dayOfWeek;

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

        $ventanaOperativa = null;

        if ($detalleHorario && (int) $detalleHorario->labora === 1) {

            $ventanaOperativa = app(VentanaOperativaService::class)->resolver(
                $fecha,
                $detalleHorario,
                $plantillaHorario
            );

            $checadas = Checada::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $id)
                ->whereBetween('check_time', [
                    $ventanaOperativa['ventana']['inicio'],
                    $ventanaOperativa['ventana']['fin'],
                ])
                ->orderBy('check_time')
                ->get();

        } else {

            $checadas = Checada::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $id)
                ->whereDate('fecha', $fecha)
                ->orderBy('check_time')
                ->get();
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
                    'fecha'             => $fecha,
                    'tiene_horario'     => true,
                    'estado_operativo'  => $checadas->count() > 0
                        ? 'dia_no_laborable_con_checadas'
                        : 'dia_no_laborable',
                    'ventana_operativa' => $ventanaOperativa,
                    'horario'           => [
                        'id_asignacion'          => $asignacion->id,
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
                    'metricas'          => [
                        'minutos_programados'       => 0,
                        'minutos_trabajados'        => 0,
                        'minutos_retardo'           => 0,
                        'minutos_salida_anticipada' => 0,
                        'minutos_comida'            => 0,
                        'minutos_break'             => 0,
                        'puntualidad_pct'           => null,
                        'productividad_pct'         => null,
                    ],
                    'alertas'           => $alertas,
                ],
            ]);
        }

        $calculoJornada = app(JornadaCalculoService::class)->calcularDia(
            $checadas,
            $detalleHorario,
            $plantillaHorario,
            $fecha
        );

        $minutosProgramados = $calculoJornada['programado']['minutos'] ?? 0;
        $minutosTrabajo     = $calculoJornada['normal']['minutos_detectados'] ?? 0;

        $minutosRetardoDetectado       = $calculoJornada['incidencias']['retardo']['detectado_minutos'] ?? 0;
        $minutosRetardoFueraTolerancia = $calculoJornada['incidencias']['retardo']['fuera_tolerancia_minutos'] ?? 0;

        $minutosSalidaAnticipadaDetectada       = $calculoJornada['incidencias']['salida_anticipada']['detectado_minutos'] ?? 0;
        $minutosSalidaAnticipadaFueraTolerancia = $calculoJornada['incidencias']['salida_anticipada']['fuera_tolerancia_minutos'] ?? 0;

        $minutosExtraDetectados = $calculoJornada['extra']['resumen']['minutos_detectados'] ?? 0;

        $minutosComida = $this->calcularMinutosPorClase(
            $checadas,
            'meal',
            $plantillaHorario->timezone
        );

        $minutosBreak = $this->calcularMinutosPorClase(
            $checadas,
            'break',
            $plantillaHorario->timezone
        );

        if ($calculoJornada['estado_jornada'] === 'sin_checadas') {
            $alertas[] = [
                'tipo'    => 'ausencia',
                'nivel'   => 'danger',
                'mensaje' => 'Día laborable sin checadas registradas.',
            ];
        }

        if ($calculoJornada['estado_jornada'] === 'sin_entrada') {
            $alertas[] = [
                'tipo'    => 'sin_entrada',
                'nivel'   => 'danger',
                'mensaje' => 'No existe entrada laboral registrada.',
            ];
        }

        if ($calculoJornada['estado_jornada'] === 'sin_salida') {
            $alertas[] = [
                'tipo'    => 'sin_salida',
                'nivel'   => 'danger',
                'mensaje' => 'No existe salida laboral registrada.',
            ];
        }

        if ($minutosRetardoDetectado > 0) {
            $alertas[] = [
                'tipo'    => 'retardo',
                'nivel'   => $minutosRetardoFueraTolerancia > 0 ? 'warning' : 'info',
                'mensaje' => $minutosRetardoFueraTolerancia > 0
                    ? "Retardo detectado de {$minutosRetardoDetectado} minutos; {$minutosRetardoFueraTolerancia} fuera de tolerancia."
                    : "Retardo detectado de {$minutosRetardoDetectado} minutos dentro de tolerancia.",
            ];
        }

        if ($minutosSalidaAnticipadaDetectada > 0) {
            $alertas[] = [
                'tipo'    => 'salida_anticipada',
                'nivel'   => $minutosSalidaAnticipadaFueraTolerancia > 0 ? 'warning' : 'info',
                'mensaje' => $minutosSalidaAnticipadaFueraTolerancia > 0
                    ? "Salida anticipada detectada de {$minutosSalidaAnticipadaDetectada} minutos; {$minutosSalidaAnticipadaFueraTolerancia} fuera de tolerancia."
                    : "Salida anticipada detectada de {$minutosSalidaAnticipadaDetectada} minutos dentro de tolerancia.",
            ];
        }

        if ($minutosExtraDetectados > 0) {
            $alertas[] = [
                'tipo'    => 'tiempo_extra_detectado',
                'nivel'   => 'warning',
                'mensaje' => "Tiempo extra detectado de {$minutosExtraDetectados} minutos pendiente de aprobación.",
            ];
        }

        $puntualidadPct = $minutosProgramados > 0
            ? max(0, round((($minutosProgramados - $minutosRetardoFueraTolerancia) / $minutosProgramados) * 100, 2))
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
                'fecha'             => $fecha,
                'tiene_horario'     => true,
                'estado_operativo'  => $estadoOperativo,
                'ventana_operativa' => $ventanaOperativa,
                'horario'           => [
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
                'metricas'          => [
                    'minutos_programados'                        => $minutosProgramados,
                    'minutos_trabajados'                         => $minutosTrabajo,

                    'minutos_retardo'                            => $minutosRetardoDetectado,
                    'minutos_retardo_fuera_tolerancia'           => $minutosRetardoFueraTolerancia,

                    'minutos_salida_anticipada'                  => $minutosSalidaAnticipadaDetectada,
                    'minutos_salida_anticipada_fuera_tolerancia' => $minutosSalidaAnticipadaFueraTolerancia,

                    'minutos_extra_detectados'                   => $minutosExtraDetectados,

                    'minutos_comida'                             => $minutosComida,
                    'minutos_break'                              => $minutosBreak,

                    'puntualidad_pct'                            => $puntualidadPct,
                    'productividad_pct'                          => $productividadPct,

                    'jornada_calculo'                            => $calculoJornada,

                ],
                'alertas'           => $alertas,
            ],
        ]);
    }
    public function metricasOperativas(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');

        $fechaInicio = $request->input(
            'fecha_inicio',
            now()->toDateString()
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

        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin    = Carbon::parse($fechaFin)->startOfDay();

        if ($fin->lessThan($inicio)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La fecha fin no puede ser menor que la fecha inicio.',
            ], 422);
        }

        $todasChecadas = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $id)
            ->whereBetween('check_time', [
                $inicio->copy()->subDay()->startOfDay(),
                $fin->copy()->addDay()->endOfDay(),
            ])
            ->orderBy('check_time')
            ->get();

        $minutosProgramados = 0;
        $minutosTrabajados  = 0;

        $minutosRetardoDetectado       = 0;
        $minutosRetardoFueraTolerancia = 0;

        $minutosSalidaAnticipadaDetectada       = 0;
        $minutosSalidaAnticipadaFueraTolerancia = 0;

        $minutosExtraDetectados = 0;

        $minutosComida               = 0;
        $minutosBreak                = 0;
        $minutosPersonal             = 0;
        $diasCalendario              = 0;
        $diasLaborables              = 0;
        $diasTrabajados              = 0;
        $diasSinChecadas             = 0;
        $diasSinHorario              = 0;
        $diasNoLaborablesConChecadas = 0;

        $alertas = [];

        $checadasProcesadas = [];
        $cursor             = $inicio->copy();

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

            $checadasDia = collect();
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

            $ventanaOperativa = app(VentanaOperativaService::class)->resolver(
                $fecha,
                $detalleHorario,
                $plantillaHorario
            );

            $checadasDia = $todasChecadas
                ->filter(function ($item) use (
                    $ventanaOperativa,
                    &$checadasProcesadas
                ) {

                    if (in_array($item->id, $checadasProcesadas, true)) {
                        return false;
                    }

                    $checkTime = $item->check_time?->format('Y-m-d H:i:s');

                    return $checkTime >= $ventanaOperativa['ventana']['inicio']
                        && $checkTime <= $ventanaOperativa['ventana']['fin'];
                })
                ->values();

            foreach ($checadasDia as $checadaProcesada) {
                $checadasProcesadas[] = $checadaProcesada->id;
            }

            $calculoJornada = app(JornadaCalculoService::class)->calcularDia(
                $checadasDia,
                $detalleHorario,
                $plantillaHorario,
                $fecha
            );

            $minutosProgramados +=
            $calculoJornada['programado']['minutos'] ?? 0;

            if ($calculoJornada['estado_jornada'] === 'sin_checadas') {

                $diasSinChecadas++;

                $alertas[] = [
                    'tipo'   => 'ausencia',
                    'nivel'  => 'danger',
                    'params' => [
                        'date' => $fecha,
                    ],
                ];

                $cursor->addDay();

                continue;
            }

            $diasTrabajados++;

            $minutosTrabajados +=
            $calculoJornada['normal']['minutos_detectados'] ?? 0;

            $minutosRetardoDetectado +=
            $calculoJornada['incidencias']['retardo']['detectado_minutos'] ?? 0;

            $minutosRetardoFueraTolerancia +=
            $calculoJornada['incidencias']['retardo']['fuera_tolerancia_minutos'] ?? 0;

            $minutosSalidaAnticipadaDetectada +=
            $calculoJornada['incidencias']['salida_anticipada']['detectado_minutos'] ?? 0;

            $minutosSalidaAnticipadaFueraTolerancia +=
            $calculoJornada['incidencias']['salida_anticipada']['fuera_tolerancia_minutos'] ?? 0;

            $minutosExtraDetectados +=
            $calculoJornada['extra']['resumen']['minutos_detectados'] ?? 0;

            $minutosComida += collect($calculoJornada['segmentos']['meal'] ?? [])
                ->sum('minutos');

            $minutosBreak += collect($calculoJornada['segmentos']['break'] ?? [])
                ->sum('minutos');

            $minutosPersonal += collect($calculoJornada['segmentos']['personal'] ?? [])
                ->sum('minutos');

            if ($calculoJornada['estado_jornada'] === 'sin_entrada') {

                $alertas[] = [
                    'tipo'   => 'sin_entrada',
                    'nivel'  => 'danger',
                    'params' => [
                        'date' => $fecha,
                    ],
                ];
            }

            if ($calculoJornada['estado_jornada'] === 'sin_salida') {

                $alertas[] = [
                    'tipo'   => 'sin_salida',
                    'nivel'  => 'warning',
                    'params' => [
                        'date' => $fecha,
                    ],
                ];
            }

            $cursor->addDay();
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

        if ($minutosExtraDetectados > 0) {

            $alertas[] = [
                'tipo'   => 'tiempo_extra_detectado',
                'nivel'  => 'warning',
                'params' => [
                    'minutos' => $minutosExtraDetectados,
                ],
            ];
        }

        $puntualidadPct = $minutosProgramados > 0
            ? max(
            0,
            round(
                (
                    ($minutosProgramados - $minutosRetardoFueraTolerancia)
                    / $minutosProgramados
                ) * 100,
                2
            )
        )
            : null;

        $productividadPct = $minutosProgramados > 0
            ? min(
            100,
            round(
                ($minutosTrabajados / $minutosProgramados) * 100,
                2
            )
        )
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

                    'dias_calendario'                            => $diasCalendario,
                    'dias_laborables'                            => $diasLaborables,
                    'dias_trabajados'                            => $diasTrabajados,
                    'dias_sin_checadas'                          => $diasSinChecadas,
                    'dias_sin_horario'                           => $diasSinHorario,
                    'dias_no_laborables_checadas'                => $diasNoLaborablesConChecadas,

                    'minutos_programados'                        => $minutosProgramados,
                    'minutos_trabajados'                         => $minutosTrabajados,

                    'minutos_retardo'                            => $minutosRetardoDetectado,
                    'minutos_retardo_fuera_tolerancia'           => $minutosRetardoFueraTolerancia,

                    'minutos_salida_anticipada'                  => $minutosSalidaAnticipadaDetectada,

                    'minutos_salida_anticipada_fuera_tolerancia' => $minutosSalidaAnticipadaFueraTolerancia,

                    'minutos_extra_detectados'                   => $minutosExtraDetectados,

                    'minutos_comida'                             => $minutosComida,
                    'minutos_break'                              => $minutosBreak,
                    'minutos_personal'                           => $minutosPersonal,
                    'puntualidad_pct'                            => $puntualidadPct,
                    'productividad_pct'                          => $productividadPct,
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

                    'tiene_biometrico' => $items->contains(function ($item) {
                        return in_array($item->origen, ['biometrico', 'reloj', 'api'], true)
                        || $item->metodo_validacion === 'biometrico';
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
