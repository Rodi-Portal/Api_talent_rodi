<?php

namespace App\Services\Checador;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceAbsenceBackfillService
{
    private string $connection = 'portal_main';

    public function registrarPendientes(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $fechaActual
    ): array {
        $fechaActual = $fechaActual->copy()->startOfDay();
        $fechaFinal  = $fechaActual->copy()->subDay();

        /*
         * Nunca se generan faltas para hoy ni para fechas futuras.
         */
        if ($fechaFinal->greaterThanOrEqualTo($fechaActual)) {
            return [];
        }

        $ultimaFechaConChecada = DB::connection($this->connection)
            ->table('checadas')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->whereDate('fecha', '<', $fechaActual->toDateString())
            ->max('fecha');

        if ($ultimaFechaConChecada) {
            $fechaInicio = Carbon::parse($ultimaFechaConChecada)
                ->addDay()
                ->startOfDay();
        } else {
            $fechaInicioAsignacion = DB::connection($this->connection)
                ->table('checador_asignaciones')
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('id_empleado', $idEmpleado)
                ->where('activa', 1)
                ->whereDate(
                    'fecha_inicio',
                    '<=',
                    $fechaFinal->toDateString()
                )
                ->min('fecha_inicio');

            if (! $fechaInicioAsignacion) {
                return [];
            }

            $fechaInicio = Carbon::parse(
                $fechaInicioAsignacion
            )->startOfDay();
        }

        if ($fechaInicio->greaterThan($fechaFinal)) {
            return [];
        }

        $faltasCreadas = [];
        $fechaRevision = $fechaInicio->copy();

        while ($fechaRevision->lessThanOrEqualTo($fechaFinal)) {
            $fecha = $fechaRevision->toDateString();

            $asignacion = $this->obtenerAsignacion(
                $idPortal,
                $idCliente,
                $idEmpleado,
                $fecha
            );

            if (! $asignacion) {
                $fechaRevision->addDay();
                continue;
            }

            if (! $this->esDiaLaborable($asignacion, $fecha)) {
                $fechaRevision->addDay();
                continue;
            }

            if (
                $this->tieneChecadas(
                    $idPortal,
                    $idCliente,
                    $idEmpleado,
                    $fecha
                )
            ) {
                $fechaRevision->addDay();
                continue;
            }

            if (
                $this->tieneJustificacion(
                    $idPortal,
                    $idCliente,
                    $idEmpleado,
                    $fecha
                )
            ) {
                $fechaRevision->addDay();
                continue;
            }

            if (
                $this->yaTieneFalta(
                    $idPortal,
                    $idCliente,
                    $idEmpleado,
                    $fecha
                )
            ) {
                $fechaRevision->addDay();
                continue;
            }

            $idEvento = DB::connection($this->connection)
                ->table('calendario_eventos')
                ->insertGetId([
                    'id_usuario'           => null,
                    'id_empleado'          => $idEmpleado,
                    'id_portal'            => $idPortal,
                    'id_cliente'           => $idCliente,
                    'inicio'               => $fecha,
                    'fin'                  => $fecha,
                    'dias_evento'          => 1,
                    'descripcion'          =>
                        'Falta por ausencia de checadas en día laborable.',
                    'archivo'              => null,
                    'id_tipo'              => 4,
                    'tipo_incapacidad_sat' => null,
                    'eliminado'            => 0,
                    'estado'               => 2,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

            $faltasCreadas[] = [
                'id'    => $idEvento,
                'fecha' => $fecha,
            ];

            $fechaRevision->addDay();
        }

        return $faltasCreadas;
    }

    private function obtenerAsignacion(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): ?object {
        return DB::connection($this->connection)
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
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
    }

    private function esDiaLaborable(
        object $asignacion,
        string $fecha
    ): bool {
        if (empty($asignacion->id_plantilla_horario)) {
            return false;
        }

        $diaSemana = Carbon::parse($fecha)->dayOfWeek;

        return DB::connection($this->connection)
            ->table('checador_horario_detalles')
            ->where(
                'id_plantilla',
                (int) $asignacion->id_plantilla_horario
            )
            ->where('dia_semana', $diaSemana)
            ->where('labora', 1)
            ->exists();
    }

    private function tieneChecadas(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): bool {
        return DB::connection($this->connection)
            ->table('checadas')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->whereDate('fecha', $fecha)
            ->exists();
    }

    private function tieneJustificacion(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): bool {
        return DB::connection($this->connection)
            ->table('calendario_eventos')
            ->where('id_empleado', $idEmpleado)
            ->whereIn('id_tipo', [1, 2, 3, 10])
            ->where('estado', 2)
            ->where('eliminado', 0)
            ->whereDate('inicio', '<=', $fecha)
            ->whereDate('fin', '>=', $fecha)
            ->where(function ($query) use ($idPortal, $idCliente) {
                $query->where(function ($contextQuery) use (
                    $idPortal,
                    $idCliente
                ) {
                    $contextQuery
                        ->where('id_portal', $idPortal)
                        ->where('id_cliente', $idCliente);
                })->orWhere(function ($legacyQuery) {
                    $legacyQuery
                        ->whereNull('id_portal')
                        ->whereNull('id_cliente');
                });
            })
            ->where(function ($query) {
                $query->where('requiere_aprobacion', 0)
                    ->orWhereIn(
                        'estado_aprobacion',
                        ['no_requiere', 'aprobado']
                    );
            })
            ->exists();
    }

    private function yaTieneFalta(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): bool {
        return DB::connection($this->connection)
            ->table('calendario_eventos')
            ->where('id_empleado', $idEmpleado)
            ->where('id_tipo', 4)
            ->where('eliminado', 0)
            ->whereDate('inicio', '<=', $fecha)
            ->whereDate('fin', '>=', $fecha)
            ->where(function ($query) use ($idPortal, $idCliente) {
                $query->where(function ($contextQuery) use (
                    $idPortal,
                    $idCliente
                ) {
                    $contextQuery
                        ->where('id_portal', $idPortal)
                        ->where('id_cliente', $idCliente);
                })->orWhere(function ($legacyQuery) {
                    $legacyQuery
                        ->whereNull('id_portal')
                        ->whereNull('id_cliente');
                });
            })
            ->exists();
    }
}