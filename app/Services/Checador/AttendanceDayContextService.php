<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\Checada;
use App\Models\Comunicacion360\Checador\ChecadorAsignacion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceDayContextService
{
    public function resolver(
        int $idPortal,
        int $idEmpleado,
        string $fecha,
        ?int $idCliente = null,
        ?Collection $asignacionesPrecargadas = null
    ): array {

        if ($asignacionesPrecargadas !== null) {
            // Reporte masivo: buscar en asignaciones precargadas.
            // No realizar otra consulta SQL.
            $asignacion = $asignacionesPrecargadas
                ->filter(function ($item) use (
                    $idPortal,
                    $idEmpleado,
                    $idCliente,
                    $fecha
                ) {
                    if (
                        (int) $item->id_portal !== $idPortal ||
                        (int) $item->id_empleado !== $idEmpleado ||
                        ! (bool) $item->activa
                    ) {
                        return false;
                    }

                    if (
                        $idCliente !== null &&
                        (int) $item->id_cliente !== $idCliente
                    ) {
                        return false;
                    }

                    $inicio = $item->fecha_inicio?->toDateString();
                    $fin    = $item->fecha_fin?->toDateString();

                    return $inicio !== null
                        && $inicio <= $fecha
                        && ($fin === null || $fin >= $fecha);
                })
                ->sort(function ($a, $b) {
                    return ((int) $b->prioridad <=> (int) $a->prioridad)
                        ?: ((int) $b->id <=> (int) $a->id);
                })
                ->first();

        } else {
            // Reporte individual: conservar consulta original.
            $asignacion = ChecadorAsignacion::query()
                ->where('id_portal', $idPortal)
                ->where('id_empleado', $idEmpleado)
                ->when(
                    $idCliente !== null,
                    fn($query) => $query->where('id_cliente', $idCliente)
                )
                ->where('activa', 1)
                ->whereDate('fecha_inicio', '<=', $fecha)
                ->where(function ($query) use ($fecha) {
                    $query->whereNull('fecha_fin')
                        ->orWhereDate('fecha_fin', '>=', $fecha);
                })
                ->orderByDesc('prioridad')
                ->orderByDesc('id')
                ->first();
        }

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
                ->when(
                    $idCliente !== null,
                    fn($query) => $query->where('id_cliente', $idCliente)
                )
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
                ->when(
                    $idCliente !== null,
                    fn($query) => $query->where('id_cliente', $idCliente)
                )
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
