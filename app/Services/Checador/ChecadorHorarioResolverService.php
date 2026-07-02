<?php
namespace App\Services\Checador;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChecadorHorarioResolverService
{
    /**
     * Resuelve la asignación y el horario aplicable
     * para un empleado en una fecha determinada.
     */
    public function resolver(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $fecha
    ): array {

        $asignacion = $this->obtenerAsignacionActiva(
            $idPortal,
            $idCliente,
            $idEmpleado,
            $fecha
        );

        if (! $asignacion) {
            return [
                'ok'            => false,
                'code'          => 'checker_assignment_not_found',
                'asignacion'    => null,
                'horario'       => null,
                'fecha_jornada' => $fecha->copy(),
            ];
        }

        $horario = $this->obtenerHorarioDelDia(
            (int) $asignacion->id_plantilla_horario,
            $fecha
        );

        if (! $horario || (int) $horario->labora !== 1) {
            return [
                'ok'            => false,
                'code'          => 'non_working_day',
                'asignacion'    => $asignacion,
                'horario'       => $horario,
                'fecha_jornada' => $fecha->copy(),
            ];
        }

        return [
            'ok'            => true,
            'code'          => 'schedule_resolved',
            'asignacion'    => $asignacion,
            'horario'       => $horario,
            'fecha_jornada' => $fecha->copy(),
        ];
    }

    private function obtenerAsignacionActiva(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $fecha
    ) {
        return DB::connection('portal_main')->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fecha->toDateString());
            })
            ->orderByDesc('prioridad')
            ->first();
    }

    private function obtenerHorarioDelDia(
        int $idPlantillaHorario,
        Carbon $fecha
    ) {
        $diaSemana = (int) $fecha->dayOfWeek;

        return DB::connection('portal_main')
            ->table('checador_horario_detalles as d')
            ->join(
                'checador_horario_plantillas as p',
                'p.id',
                '=',
                'd.id_plantilla'
            )
            ->where('d.id_plantilla', $idPlantillaHorario)
            ->where('d.dia_semana', $diaSemana)
            ->select(
                'd.*',
                'p.tolerancia_entrada_min',
                'p.tolerancia_salida_min',
                'p.permite_descanso'
            )
            ->first();
    }

    private function resolverHorarioAplicable(
        int $idPlantillaHorario,
        Carbon $checkTime
    ): array {
        $candidatos = [
            $checkTime->copy(),
            $checkTime->copy()->subDay(),
        ];

        foreach ($candidatos as $fechaCandidata) {

            $horario = $this->obtenerHorarioDelDia(
                $idPlantillaHorario,
                $fechaCandidata
            );

            if (
                ! $horario ||
                (int) $horario->labora !== 1 ||
                ! $horario->hora_entrada ||
                ! $horario->hora_salida
            ) {
                continue;
            }

            $inicio = Carbon::parse(
                $fechaCandidata->toDateString() . ' ' . $horario->hora_entrada
            );

            $fin = Carbon::parse(
                $fechaCandidata->toDateString() . ' ' . $horario->hora_salida
            );

            if ($fin->lessThanOrEqualTo($inicio)) {
                $fin->addDay();
            }

            $inicioVentana = $inicio->copy()->subHours(4);
            $finVentana    = $fin->copy()->addHours(4);

            if (
                $checkTime->greaterThanOrEqualTo($inicioVentana)
                && $checkTime->lessThanOrEqualTo($finVentana)
            ) {
                return [
                    'fecha_jornada' => $fechaCandidata,
                    'horario'       => $horario,
                ];
            }
        }

        return [
            'fecha_jornada' => $checkTime->copy(),
            'horario'       => null,
        ];
    }
}
