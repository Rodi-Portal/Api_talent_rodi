<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\Checada;
use App\Models\Comunicacion360\Checador\ChecadorAsignacion;
use Carbon\Carbon;

class AttendanceDayContextService
{
    public function resolver(
        int $idPortal,
        int $idEmpleado,
        string $fecha
    ): array {
        $asignacion = ChecadorAsignacion::query()
            ->where('id_portal', $idPortal)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->where(function ($query) use ($fecha) {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fecha);
            })
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        $plantillaHorario = null;
        $detalleHorario   = null;
        $ventanaOperativa = null;

        if ($asignacion && $asignacion->id_plantilla_horario) {
            $plantillaHorario = $asignacion->horarioPlantilla;

            if ($plantillaHorario) {
                $diaSemana = Carbon::parse($fecha)->dayOfWeek;

                $detalleHorario = $plantillaHorario->detalles
                    ->where('dia_semana', $diaSemana)
                    ->sortBy('orden')
                    ->first();
            }
        }

        if ($detalleHorario && (int) $detalleHorario->labora === 1) {
            $ventanaOperativa = app(VentanaOperativaService::class)->resolver(
                $fecha,
                $detalleHorario,
                $plantillaHorario
            );
        }
        if ($ventanaOperativa) {
            $checadas = Checada::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $idEmpleado)
                ->whereBetween('check_time', [
                    $ventanaOperativa['ventana']['inicio'],
                    $ventanaOperativa['ventana']['fin'],
                ])
                ->orderBy('check_time')
                ->get();
        } else {
            $checadas = Checada::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $idEmpleado)
                ->whereDate('fecha', $fecha)
                ->orderBy('check_time')
                ->get();
        }

        return [
            'asignacion'       => $asignacion,
            'plantillaHorario' => $plantillaHorario,
            'detalleHorario'   => $detalleHorario,
            'ventanaOperativa' => $ventanaOperativa,
            'checadas'         => $checadas,
        ];
    }
}
