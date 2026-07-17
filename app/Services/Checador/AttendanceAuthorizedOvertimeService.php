<?php
namespace App\Services\Checador;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceAuthorizedOvertimeService
{
    public const POLITICA_SOLO_AUTORIZADO = 'solo_autorizado';

    public const POLITICA_TIEMPO_REAL_POSTERIOR = 'tiempo_real_posterior';

    public function calcular(
        string $fecha,
        array $segmentosOperativos,
        Collection $autorizaciones,
        string $politica = self::POLITICA_SOLO_AUTORIZADO
    ): array {
        $detalles        = [];
        $totalReconocido = 0;

        foreach ($autorizaciones as $autorizacion) {
            $inicioAutorizado = Carbon::parse(
                $fecha . ' ' . $autorizacion->hora_inicio
            );

            $finAutorizado = Carbon::parse(
                $fecha . ' ' . $autorizacion->hora_fin
            );

            if ($finAutorizado->lessThanOrEqualTo($inicioAutorizado)) {
                $finAutorizado->addDay();
            }

            $minutosTrabajadosDentro = 0;

            foreach ($segmentosOperativos as $segmento) {
                if (
                    empty($segmento['inicio']) ||
                    empty($segmento['fin']) ||
                    ! empty($segmento['cierre_virtual'])
                ) {
                    continue;
                }

                $inicioReal = Carbon::parse($segmento['inicio']);
                $finReal    = Carbon::parse($segmento['fin']);

                $inicioInterseccion = $inicioReal->greaterThan($inicioAutorizado)
                    ? $inicioReal->copy()
                    : $inicioAutorizado->copy();

                $finInterseccion = $finReal->lessThan($finAutorizado)
                    ? $finReal->copy()
                    : $finAutorizado->copy();

                if ($finInterseccion->greaterThan($inicioInterseccion)) {
                    $minutosTrabajadosDentro +=
                    $inicioInterseccion->diffInMinutes($finInterseccion);
                }
            }

            $minutosAprobados = (int) $autorizacion->minutos_aprobados;

            $minutosCubiertos = min(
                $minutosTrabajadosDentro,
                $minutosAprobados
            );

            // Solo se reconocen bloques completos de una hora.
            $minutosReconocidos = intdiv($minutosCubiertos, 60) * 60;

            $totalReconocido += $minutosReconocidos;

            $detalles[] = [
                'hora_inicio'               => $autorizacion->hora_inicio,
                'hora_fin'                  => $autorizacion->hora_fin,
                'minutos_aprobados'         => $minutosAprobados,
                'minutos_trabajados_dentro' => $minutosTrabajadosDentro,
                'minutos_reconocidos'       => $minutosReconocidos,
            ];
        }

        return [
            'politica'            => $politica,
            'minutos_reconocidos' => $totalReconocido,
            'detalles'            => $detalles,
        ];
    }
}
