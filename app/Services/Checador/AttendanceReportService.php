<?php
namespace App\Services\Checador;

use App\Services\ChecadorHorasExtraService;
use Carbon\CarbonPeriod;

class AttendanceReportService
{
    public function __construct(
        private AttendanceDayContextService $dayContextService,
        private JornadaCalculoService $jornadaCalculoService,
        private ChecadorHorasExtraService $horasExtraService,
        private AttendanceAuthorizedOvertimeService $authorizedOvertimeService
    ) {
    }

    public function generarVistaPrevia(
        int $idPortal,
        int $idEmpleado,
        string $fechaInicio,
        string $fechaFin
    ): array {
        $dias    = [];
        $resumen = [
            'dias'        => [
                'laborables'     => 0,
                'con_asistencia' => 0,
                'sin_asistencia' => 0,
            ],

            'horas'       => [
                'normales'        => 0,
                'extra'           => 0,
                'contabilizables' => 0,
            ],

            'incidencias' => [
                'retardos'            => 0,
                'salidas_anticipadas' => 0,
                'sin_salida'          => 0,
            ],
        ];

        $periodo = CarbonPeriod::create($fechaInicio, $fechaFin);

        foreach ($periodo as $fecha) {
            $fechaString = $fecha->toDateString();

            $contexto = $this->dayContextService->resolver(
                $idPortal,
                $idEmpleado,
                $fechaString
            );
            $asignacion          = $contexto['asignacion'];
            $plantillaHorario    = $contexto['plantillaHorario'];
            $detalleHorario      = $contexto['detalleHorario'];
            $checadas            = $contexto['checadas'];
            $calculoJornada      = null;
            $horasExtraAprobadas = $this->horasExtraService
                ->obtenerAprobadasPorFecha(
                    $idPortal,
                    $idEmpleado,
                    $fechaString
                );
            if (
                $asignacion &&
                $plantillaHorario &&
                $detalleHorario &&
                $detalleHorario->labora
            ) {
                $calculoJornada = $this->jornadaCalculoService->calcularDia(
                    $checadas,
                    $detalleHorario,
                    $plantillaHorario,
                    $fechaString
                );
            }
            $tiempoExtraAutorizado = [
                'politica'            => AttendanceAuthorizedOvertimeService::POLITICA_SOLO_AUTORIZADO,
                'minutos_reconocidos' => 0,
                'detalles'            => [],
            ];

            if ($calculoJornada && $horasExtraAprobadas->isNotEmpty()) {
                $tiempoExtraAutorizado = $this->authorizedOvertimeService->calcular(
                    $fechaString,
                    $calculoJornada['segmentos']['operativos'] ?? [],
                    $horasExtraAprobadas,
                    AttendanceAuthorizedOvertimeService::POLITICA_SOLO_AUTORIZADO
                );
            }
            $checadasOrdenadas = $checadas
                ->sortBy('check_time')
                ->values();

            $movimientosRegistrados = $checadasOrdenadas
                ->map(function ($checada) {
                    return [
                        'id'          => $checada->id,
                        'fecha_hora'  => $checada->check_time?->format('Y-m-d H:i:s'),
                        'hora'        => $checada->check_time?->format('H:i:s'),
                        'tipo'        => $checada->tipo,
                        'clase'       => $checada->clase,
                        'observacion' => $checada->observacion,
                    ];
                })
                ->values()
                ->all();
            $resumenMovimientos = $this->construirResumenMovimientos(
                $movimientosRegistrados
            );
            $incidencias = $this->construirIncidencias(
                $calculoJornada,
                (bool) ($detalleHorario?->labora ?? false),
                $checadas->count()
            );
            foreach ($incidencias as $incidencia) {
                $tipo = is_array($incidencia)
                    ? ($incidencia['tipo'] ?? null)
                    : $incidencia;

                if ($tipo === 'retardo') {
                    $resumen['incidencias']['retardos']++;
                }

                if ($tipo === 'salida_anticipada') {
                    $resumen['incidencias']['salidas_anticipadas']++;
                }

                if ($tipo === 'sin_salida_registrada') {
                    $resumen['incidencias']['sin_salida']++;
                }
            }
            $estadoReporte = $this->construirEstadoReporte(
                $calculoJornada,
                (bool) ($detalleHorario?->labora ?? false),
                $checadas->count()
            );
            $filaReporte = $this->construirFilaReporte(
                $fechaString,
                $detalleHorario,
                $resumenMovimientos,
                $estadoReporte,
                $calculoJornada,
                $tiempoExtraAutorizado
            );
            if ($detalleHorario?->labora) {
                $resumen['dias']['laborables']++;

                if ($checadas->isNotEmpty()) {
                    $resumen['dias']['con_asistencia']++;
                } else {
                    $resumen['dias']['sin_asistencia']++;
                }
            }
            $esJornadaContabilizable =
            $calculoJornada &&
            ! ($calculoJornada['real']['salida_virtual'] ?? false) &&
            ! in_array(
                $calculoJornada['estado_jornada'] ?? null,
                ['sin_checadas', 'sin_entrada', 'sin_salida', 'sin_salida_cerrada_virtual'],
                true
            );

            if ($esJornadaContabilizable) {
                $minutosNormales =
                $calculoJornada['normal']['minutos_detectados'] ?? 0;

                $minutosExtra =
                $tiempoExtraAutorizado['minutos_reconocidos'] ?? 0;

                $resumen['horas']['normales']        += $minutosNormales;
                $resumen['horas']['extra']           += $minutosExtra;
                $resumen['horas']['contabilizables'] +=
                    $minutosNormales + $minutosExtra;
            }
            $dias[] = [
                'fecha'                   => $fechaString,
                'tiene_asignacion'        => ! empty($asignacion),
                'tiene_horario'           => ! empty($plantillaHorario),
                'labora'                  => (bool) ($detalleHorario?->labora ?? false),

                'horario'                 => [
                    'entrada' => $detalleHorario?->hora_entrada,
                    'salida'  => $detalleHorario?->hora_salida,
                ],

                'total_checadas'          => $checadas->count(),
                'movimientos_registrados' => $movimientosRegistrados,
                'entrada_registrada'      => $resumenMovimientos['entrada'],
                'movimientos_intermedios' => $resumenMovimientos['intermedios'],
                'salida_registrada'       => $resumenMovimientos['salida'],
                'incidencias'             => $incidencias,
                'estado_reporte'          => $estadoReporte,
                'fila_reporte'            => $filaReporte,
                'horas_extra_aprobadas'   => $horasExtraAprobadas
                    ->map(function ($horaExtra) {
                        return [
                            'fecha'             => $horaExtra->fecha,
                            'hora_inicio'       => $horaExtra->hora_inicio,
                            'hora_fin'          => $horaExtra->hora_fin,
                            'minutos_aprobados' => (int) $horaExtra->minutos_aprobados,
                            'minutos_pagables'  => (int) $horaExtra->minutos_pagables,
                            'impacta_prenomina' => (bool) $horaExtra->impacta_prenomina,
                        ];
                    })
                    ->values()
                    ->all(),
                'tiempo_extra_autorizado' => $tiempoExtraAutorizado,
                'jornada'                 => [
                    'estado'                    => $calculoJornada['estado_jornada'] ?? null,
                    'entrada'                   => $calculoJornada['real']['entrada'] ?? null,
                    'salida'                    => $calculoJornada['real']['salida'] ?? null,
                    'salida_virtual'            => $calculoJornada['real']['salida_virtual'] ?? false,
                    'minutos_trabajados'        => $calculoJornada['normal']['minutos_detectados'] ?? 0,
                    'minutos_extra_reconocidos' => $tiempoExtraAutorizado['minutos_reconocidos'] ?? 0,
                    'minutos_contabilizables'   => $esJornadaContabilizable
                        ? (
                        ($calculoJornada['normal']['minutos_detectados'] ?? 0)
                         + ($tiempoExtraAutorizado['minutos_reconocidos'] ?? 0)
                    )
                        : 0,
                ],
            ];
        }

        return [
            'periodo' => [
                'fecha_inicio' => $fechaInicio,
                'fecha_fin'    => $fechaFin,
            ],

            'resumen' => $resumen,

            'dias'    => $dias,
        ];
    }
    private function construirResumenMovimientos(array $movimientos): array
    {
        $total = count($movimientos);

        if ($total === 0) {
            return [
                'entrada'     => null,
                'intermedios' => [],
                'salida'      => null,
            ];
        }

        if ($total === 1) {
            $movimiento = $movimientos[0];

            return [
                'entrada'     => $movimiento['tipo'] === 'in'
                    ? $movimiento
                    : null,

                'intermedios' => [],

                'salida'      => $movimiento['tipo'] === 'out'
                    ? $movimiento
                    : null,
            ];
        }

        return [
            'entrada'     => $movimientos[0],
            'intermedios' => array_slice($movimientos, 1, -1),
            'salida'      => $movimientos[$total - 1],
        ];
    }
    private function construirIncidencias(
        ?array $calculoJornada,
        bool $labora,
        int $totalChecadas
    ): array {
        if (! $labora) {
            return $totalChecadas > 0
                ? ['dia_no_laborable_con_checadas']
                : [];
        }

        if (! $calculoJornada) {
            return [];
        }

        $incidencias = [];

        $estado = $calculoJornada['estado_jornada'] ?? null;

        if ($estado === 'sin_checadas') {
            $incidencias[] = 'sin_checadas';
        }

        if ($estado === 'sin_entrada') {
            $incidencias[] = 'sin_entrada';
        }

        if (in_array($estado, ['sin_salida', 'sin_salida_cerrada_virtual'], true)) {
            $incidencias[] = 'sin_salida_registrada';
        }

        $minutosRetardo = $calculoJornada['incidencias']['retardo']['detectado_minutos'] ?? 0;

        if ($minutosRetardo > 0) {
            $incidencias[] = [
                'tipo'    => 'retardo',
                'minutos' => $minutosRetardo,
            ];
        }

        $minutosSalidaAnticipada =
        $calculoJornada['incidencias']['salida_anticipada']['detectado_minutos'] ?? 0;

        if ($minutosSalidaAnticipada > 0) {
            $incidencias[] = [
                'tipo'    => 'salida_anticipada',
                'minutos' => $minutosSalidaAnticipada,
            ];
        }

        $minutosExtra =
        $calculoJornada['extra']['resumen']['minutos_detectados'] ?? 0;

        if ($minutosExtra > 0) {
            $incidencias[] = [
                'tipo'    => 'tiempo_extra_detectado',
                'minutos' => $minutosExtra,
            ];
        }

        return $incidencias;
    }
    private function construirEstadoReporte(
        ?array $calculoJornada,
        bool $labora,
        int $totalChecadas
    ): array {
        if (! $labora) {
            return [
                'codigo'      => $totalChecadas > 0
                    ? 'no_laborable_con_checadas'
                    : 'no_laborable',
                'descripcion' => $totalChecadas > 0
                    ? 'Día no laborable con checadas'
                    : 'Día no laborable',
            ];
        }

        if (! $calculoJornada) {
            return [
                'codigo'      => 'sin_calculo',
                'descripcion' => 'Sin información de jornada',
            ];
        }

        $estado = $calculoJornada['estado_jornada'] ?? null;

        return match ($estado) {
            'completa'                   => [
                'codigo'      => 'completa',
                'descripcion' => 'Jornada completa',
            ],

            'sin_checadas'               => [
                'codigo'      => 'sin_checadas',
                'descripcion' => 'Sin asistencia registrada',
            ],

            'sin_entrada'                => [
                'codigo'      => 'sin_entrada',
                'descripcion' => 'Entrada no registrada',
            ],

            'sin_salida',
            'sin_salida_cerrada_virtual' => [
                'codigo'      => 'sin_salida',
                'descripcion' => 'Salida no registrada',
            ],

            default                      => [
                'codigo'      => 'incompleta',
                'descripcion' => 'Jornada incompleta',
            ],
        };
    }
    private function construirFilaReporte(
        string $fecha,
        $detalleHorario,
        array $movimientos,
        array $estadoReporte,
        ?array $calculoJornada,
        array $tiempoExtra
    ): array {
        $esJornadaContabilizable =
        $calculoJornada &&
        ! ($calculoJornada['real']['salida_virtual'] ?? false) &&
        ! in_array(
            $calculoJornada['estado_jornada'] ?? null,
            [
                'sin_checadas',
                'sin_entrada',
                'sin_salida',
                'sin_salida_cerrada_virtual',
            ],
            true
        );

        $minutosNormales = $esJornadaContabilizable
            ? ($calculoJornada['normal']['minutos_detectados'] ?? 0)
            : 0;

        $minutosExtra = $esJornadaContabilizable
            ? ($tiempoExtra['minutos_reconocidos'] ?? 0)
            : 0;
        return [
            'fecha'               => $fecha,

            'horario'             => $this->formatearHorario(
                $detalleHorario?->hora_entrada,
                $detalleHorario?->hora_salida
            ),

            'entrada'             => isset($movimientos['entrada']['hora'])
                ? substr($movimientos['entrada']['hora'], 0, 5)
                : '-',
            'movimientos'         => $this->formatearMovimientos(
                $movimientos['intermedios']
            ),
            'movimientos_detalle' => $this->construirDetalleMovimientos(
                $movimientos['intermedios']
            ),

            'salida'              => isset($movimientos['salida']['hora'])
                ? substr($movimientos['salida']['hora'], 0, 5)
                : '-',
            'estado'              => $estadoReporte['descripcion'],

            'horas_normales'      => $this->formatearMinutos(
                $minutosNormales
            ),

            'horas_extra'         => $this->formatearMinutos(
                $minutosExtra
            ),

            'horas_totales'       => $this->formatearMinutos(
                $minutosNormales + $minutosExtra
            ),

            'firma'               => null,
        ];
    }
    private function construirDetalleMovimientos(
        array $movimientos
    ): array {
        $salidasPendientes = [];
        $detalles          = [];

        foreach ($movimientos as $movimiento) {
            $clase = $movimiento['clase'] ?? 'other';
            $tipo  = $movimiento['tipo'] ?? null;
            $hora  = isset($movimiento['hora'])
                ? substr($movimiento['hora'], 0, 5)
                : null;

            if (! $hora || ! in_array($tipo, ['in', 'out'], true)) {
                continue;
            }

            if ($tipo === 'out') {
                $salidasPendientes[$clase][] = $hora;
                continue;
            }

            $horaInicio = null;

            if (! empty($salidasPendientes[$clase])) {
                $horaInicio = array_shift(
                    $salidasPendientes[$clase]
                );
            }

            $detalles[] = [
                'clase'  => $clase,
                'inicio' => $horaInicio,
                'fin'    => $hora,
            ];
        }

        foreach ($salidasPendientes as $clase => $horas) {
            foreach ($horas as $horaInicio) {
                $detalles[] = [
                    'clase'  => $clase,
                    'inicio' => $horaInicio,
                    'fin'    => null,
                ];
            }
        }

        usort(
            $detalles,
            fn(array $a, array $b) =>
            strcmp($a['inicio'] ?? $a['fin'] ?? '', $b['inicio'] ?? $b['fin'] ?? '')
        );

        return $detalles;
    }
    private function formatearMinutos(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return sprintf('%02d:%02d', $horas, $resto);
    }
    private function formatearHorario(
        ?string $entrada,
        ?string $salida
    ): string {
        if (! $entrada || ! $salida) {
            return '-';
        }

        return substr($entrada, 0, 5)
        . ' - '
        . substr($salida, 0, 5);
    }
    private function formatearMovimientos(array $movimientos): string
    {
        if (empty($movimientos)) {
            return '-';
        }

        return implode(
            ' • ',
            array_map(
                fn($movimiento) => substr($movimiento['hora'], 0, 5),
                $movimientos
            )
        );
    }
}
