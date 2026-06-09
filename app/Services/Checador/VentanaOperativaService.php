<?php

namespace App\Services\Checador;

use Carbon\Carbon;

class VentanaOperativaService
{
    public function resolver(
        string $fecha,
        object $detalleHorario,
        object $plantillaHorario
    ): array {

        $timezone = $plantillaHorario->timezone
            ?? config('app.timezone', 'UTC');

        $inicioProgramado = Carbon::parse(
            $fecha . ' ' . $detalleHorario->hora_entrada,
            $timezone
        );

        $finProgramado = Carbon::parse(
            $fecha . ' ' . $detalleHorario->hora_salida,
            $timezone
        );

        // Jornada nocturna
        if ($finProgramado->lessThanOrEqualTo($inicioProgramado)) {
            $finProgramado->addDay();
        }

        // Margen operativo configurable futuro
        $inicioVentana = $inicioProgramado->copy()->subHours(4);

        $finVentana = $finProgramado->copy()->addHours(4);

        return [
            'timezone' => $timezone,

            'programado' => [
                'inicio' => $inicioProgramado->format('Y-m-d H:i:s'),
                'fin'    => $finProgramado->format('Y-m-d H:i:s'),
            ],

            'ventana' => [
                'inicio' => $inicioVentana->format('Y-m-d H:i:s'),
                'fin'    => $finVentana->format('Y-m-d H:i:s'),
            ],
        ];
    }
}