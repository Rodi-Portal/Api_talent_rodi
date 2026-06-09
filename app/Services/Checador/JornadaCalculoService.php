<?php
namespace App\Services\Checador;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class JornadaCalculoService
{
    private const CLASES_OPERATIVAS = ['work', 'transfer'];

    public function calcularDia(
        Collection $checadas,
        object $detalleHorario,
        object $plantillaHorario,
        string $fecha
    ): array {
        $timezone = $plantillaHorario->timezone ?? config('app.timezone', 'UTC');

        $inicioProgramado = Carbon::parse(
            $fecha . ' ' . $detalleHorario->hora_entrada,
            $timezone
        );

        $finProgramado = Carbon::parse(
            $fecha . ' ' . $detalleHorario->hora_salida,
            $timezone
        );

        if ($finProgramado->lessThanOrEqualTo($inicioProgramado)) {
            $finProgramado->addDay();
        }

        $periodosOperativos = $this->resolverPeriodosOperativos($checadas, $timezone);

        $periodosPorClase = [
            'work'     => $this->resolverPeriodosPorClase($checadas, 'work', $timezone),
            'transfer' => $this->resolverPeriodosPorClase($checadas, 'transfer', $timezone),
            'meal'     => $this->resolverPeriodosPorClase($checadas, 'meal', $timezone),
            'break'    => $this->resolverPeriodosPorClase($checadas, 'break', $timezone),
            'personal' => $this->resolverPeriodosPorClase($checadas, 'personal', $timezone),
            'other'    => $this->resolverPeriodosPorClase($checadas, 'other', $timezone),
        ];

        $estadoJornada = $this->resolverEstadoJornada($checadas, $periodosOperativos);

        $minutosBrutos            = 0;
        $minutosNormales          = 0;
        $minutosExtraAntes        = 0;
        $minutosExtraDespues      = 0;
        $minutosExtraFueraVentana = 0;

        foreach ($periodosOperativos as $periodo) {
            $inicioReal = $periodo['inicio'];
            $finReal    = $periodo['fin'];

            if (! $finReal) {
                continue;
            }

            $minutosBrutos += $inicioReal->diffInMinutes($finReal);

            $minutosNormales += $this->minutosInterseccion(
                $inicioReal,
                $finReal,
                $inicioProgramado,
                $finProgramado
            );

            if ($inicioReal->lessThan($inicioProgramado)) {
                $minutosExtraAntes += $this->minutosInterseccion(
                    $inicioReal,
                    $finReal,
                    $inicioReal,
                    $inicioProgramado
                );
            }

            if ($finReal->greaterThan($finProgramado)) {
                $minutosExtraDespues += $this->minutosInterseccion(
                    $inicioReal,
                    $finReal,
                    $finProgramado,
                    $finReal
                );
            }

            if (
                $finReal->lessThanOrEqualTo($inicioProgramado) ||
                $inicioReal->greaterThanOrEqualTo($finProgramado)
            ) {
                $minutosExtraFueraVentana += $inicioReal->diffInMinutes($finReal);
            }
        }

        $minutosExtraDetectados = $minutosExtraAntes
             + $minutosExtraDespues
             + $minutosExtraFueraVentana;

        $tipoJornada = $this->resolverTipoJornada(
            $minutosNormales,
            $minutosExtraDetectados
        );

        $toleranciaEntrada = (int) ($plantillaHorario->tolerancia_entrada_min ?? 0);
        $toleranciaSalida  = (int) ($plantillaHorario->tolerancia_salida_min ?? 0);

        $entradaReal  = $this->obtenerPrimeraEntrada($periodosOperativos);
        $salidaReal   = $this->obtenerUltimaSalidaConFin($periodosOperativos);

        $minutosRetardoDetectado        = 0;
        $minutosRetardoFueraTolerancia  = 0;

        if ($entradaReal && $entradaReal->greaterThan($inicioProgramado)) {
            $minutosRetardoDetectado = $inicioProgramado->diffInMinutes($entradaReal);

            $minutosRetardoFueraTolerancia = max(
                0,
                $minutosRetardoDetectado - $toleranciaEntrada
            );
        }

        $minutosSalidaAnticipadaDetectada       = 0;
        $minutosSalidaAnticipadaFueraTolerancia = 0;

        if ($salidaReal && $salidaReal->lessThan($finProgramado)) {
            $minutosSalidaAnticipadaDetectada = $salidaReal->diffInMinutes($finProgramado);

            $minutosSalidaAnticipadaFueraTolerancia = max(
                0,
                $minutosSalidaAnticipadaDetectada - $toleranciaSalida
            );
        }

        return [
            'fecha'          => $fecha,
            'estado_jornada' => $estadoJornada,
            'tipo_jornada'   => $tipoJornada,

            'programado'     => [
                'inicio'  => $inicioProgramado->format('Y-m-d H:i:s'),
                'fin'     => $finProgramado->format('Y-m-d H:i:s'),
                'minutos' => $inicioProgramado->diffInMinutes($finProgramado),
            ],

            'real'           => [
                'entrada'        => $entradaReal
                    ? $entradaReal->format('Y-m-d H:i:s')
                    : null,

                'salida'         => $salidaReal
                    ? $salidaReal->format('Y-m-d H:i:s')
                    : null,

                'minutos_brutos' => $minutosBrutos,
            ],

            'normal'         => [
                'minutos_detectados' => $minutosNormales,
                'minutos_pagables'   => null,
            ],

            'incidencias'    => [
                'retardo'           => [
                    'detectado_minutos'        => $minutosRetardoDetectado,
                    'fuera_tolerancia_minutos' => $minutosRetardoFueraTolerancia,
                    'descontable_minutos'      => null,
                ],

                'salida_anticipada' => [
                    'detectado_minutos'        => $minutosSalidaAnticipadaDetectada,
                    'fuera_tolerancia_minutos' => $minutosSalidaAnticipadaFueraTolerancia,
                    'descontable_minutos'      => null,
                ],
            ],

            'extra'          => [

                'antes_jornada'   => [
                    'minutos_detectados'     => $minutosExtraAntes,
                    'minutos_aprobados'      => 0,
                    'minutos_pagables'       => 0,
                    'minutos_no_autorizados' => $minutosExtraAntes,
                ],

                'despues_jornada' => [
                    'minutos_detectados'     => $minutosExtraDespues,
                    'minutos_aprobados'      => 0,
                    'minutos_pagables'       => 0,
                    'minutos_no_autorizados' => $minutosExtraDespues,
                ],

                'fuera_ventana'   => [
                    'minutos_detectados'     => $minutosExtraFueraVentana,
                    'minutos_aprobados'      => 0,
                    'minutos_pagables'       => 0,
                    'minutos_no_autorizados' => $minutosExtraFueraVentana,
                ],

                'resumen'         => [
                    'minutos_detectados'     => $minutosExtraDetectados,
                    'minutos_aprobados'      => 0,
                    'minutos_pagables'       => 0,
                    'minutos_no_autorizados' => $minutosExtraDetectados,
                ],
            ],

            'segmentos'      => [
                'operativos' => $this->serializarPeriodos($periodosOperativos),
                'work'       => $this->serializarPeriodos($periodosPorClase['work']),
                'transfer'   => $this->serializarPeriodos($periodosPorClase['transfer']),
                'meal'       => $this->serializarPeriodos($periodosPorClase['meal']),
                'break'      => $this->serializarPeriodos($periodosPorClase['break']),
                'personal'   => $this->serializarPeriodos($periodosPorClase['personal']),
                'other'      => $this->serializarPeriodos($periodosPorClase['other']),
            ],
        ];
    }

    private function resolverPeriodosOperativos(
        Collection $checadas,
        string $timezone
    ): array {
        $periodos = [];

        foreach (self::CLASES_OPERATIVAS as $clase) {
            $periodos = array_merge(
                $periodos,
                $this->resolverPeriodosPorClase($checadas, $clase, $timezone)
            );
        }

        usort($periodos, function (array $a, array $b): int {
            return $a['inicio']->timestamp <=> $b['inicio']->timestamp;
        });

        return $this->fusionarPeriodosOperativos($periodos);
    }
    private function fusionarPeriodosOperativos(array $periodos): array
    {
        $fusionados = [];

        foreach ($periodos as $periodo) {
            if (empty($periodo['fin'])) {
                $fusionados[] = $periodo;
                continue;
            }

            if (empty($fusionados)) {
                $fusionados[] = $periodo;
                continue;
            }

            $ultimoIndex = count($fusionados) - 1;
            $ultimo      = $fusionados[$ultimoIndex];

            if (
                ! empty($ultimo['fin']) &&
                $periodo['inicio']->lessThanOrEqualTo($ultimo['fin'])
            ) {
                if ($periodo['fin']->greaterThan($ultimo['fin'])) {
                    $fusionados[$ultimoIndex]['fin'] = $periodo['fin'];
                }

                $fusionados[$ultimoIndex]['clase'] = 'operativo';
                continue;
            }

            $fusionados[] = $periodo;
        }

        return $fusionados;
    }

    private function resolverEstadoJornada(
        Collection $checadas,
        array $periodosOperativos
    ): string {
        if ($checadas->count() === 0) {
            return 'sin_checadas';
        }

        if (empty($periodosOperativos)) {
            return 'sin_entrada';
        }

        foreach ($periodosOperativos as $periodo) {
            if (($periodo['incompleto'] ?? false) === true) {
                return 'sin_salida';
            }
        }

        return 'completa';
    }

    private function resolverTipoJornada(
        int $minutosNormales,
        int $minutosExtraDetectados
    ): string {
        if ($minutosNormales > 0 && $minutosExtraDetectados > 0) {
            return 'mixta';
        }

        if ($minutosNormales === 0 && $minutosExtraDetectados > 0) {
            return 'fuera_horario';
        }

        return 'normal';
    }

    private function obtenerPrimeraEntrada(array $periodos): ?Carbon
    {
        if (empty($periodos)) {
            return null;
        }

        return $periodos[0]['inicio']->copy();
    }

    private function obtenerUltimaSalidaConFin(array $periodos): ?Carbon
    {
        $periodosConFin = array_filter($periodos, function (array $periodo): bool {
            return ! empty($periodo['fin']);
        });

        if (empty($periodosConFin)) {
            return null;
        }

        $periodosConFin = array_values($periodosConFin);

        return $periodosConFin[count($periodosConFin) - 1]['fin']->copy();
    }

    private function resolverPeriodosPorClase(
        Collection $checadas,
        string $clase,
        string $timezone
    ): array {
        $eventos = $checadas
            ->where('clase', $clase)
            ->sortBy('check_time')
            ->values();

        $periodos = [];
        $inicio   = null;

        $tipoApertura = $clase === 'work' ? 'in' : 'out';
        $tipoCierre   = $clase === 'work' ? 'out' : 'in';

        foreach ($eventos as $evento) {
            $checkTime = $this->parseCheckTime($evento->check_time, $timezone);

            if ($evento->tipo === $tipoApertura) {
                if ($inicio) {
                    $periodos[] = [
                        'clase'      => $clase,
                        'inicio'     => $inicio,
                        'fin'        => null,
                        'incompleto' => true,
                        'motivo'     => 'sin_salida',
                    ];
                }

                $inicio = $checkTime;
                continue;
            }

            if ($evento->tipo === $tipoCierre && $inicio) {
                if ($checkTime->greaterThan($inicio)) {
                    $periodos[] = [
                        'clase'      => $clase,
                        'inicio'     => $inicio,
                        'fin'        => $checkTime,
                        'incompleto' => false,
                        'motivo'     => null,
                    ];
                }

                $inicio = null;
            }
        }

        if ($inicio) {
            $periodos[] = [
                'clase'      => $clase,
                'inicio'     => $inicio,
                'fin'        => null,
                'incompleto' => true,
                'motivo'     => 'sin_salida',
            ];
        }

        return $periodos;
    }

    private function serializarPeriodos(array $periodos): array
    {
        return array_map(function (array $periodo): array {
            return [
                'clase'      => $periodo['clase'] ?? null,
                'inicio'     => $periodo['inicio']->format('Y-m-d H:i:s'),
                'fin'        => $periodo['fin']
                    ? $periodo['fin']->format('Y-m-d H:i:s')
                    : null,
                'minutos'    => $periodo['fin']
                    ? $periodo['inicio']->diffInMinutes($periodo['fin'])
                    : 0,
                'incompleto' => (bool) ($periodo['incompleto'] ?? false),
                'motivo'     => $periodo['motivo'] ?? null,
            ];
        }, $periodos);
    }

    private function minutosInterseccion(
        Carbon $inicioA,
        Carbon $finA,
        Carbon $inicioB,
        Carbon $finB
    ): int {
        $inicio = $inicioA->greaterThan($inicioB)
            ? $inicioA->copy()
            : $inicioB->copy();

        $fin = $finA->lessThan($finB)
            ? $finA->copy()
            : $finB->copy();

        if ($fin->lessThanOrEqualTo($inicio)) {
            return 0;
        }

        return $inicio->diffInMinutes($fin);
    }

    private function parseCheckTime($value, string $timezone): Carbon
    {
        $raw = $value instanceof Carbon
            ? $value->format('Y-m-d H:i:s')
            : (string) $value;

        return Carbon::createFromFormat('Y-m-d H:i:s', $raw, $timezone);
    }
}
