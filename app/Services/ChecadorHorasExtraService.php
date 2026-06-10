<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class ChecadorHorasExtraService
{
    private const TIPO_HORAS_EXTRA = 9;

    public function registrar(array $data, object $employee): array
    {
        return DB::connection('portal_main')->transaction(function () use ($data, $employee) {
            $estadoAprobacion = $data['modo_aprobacion'] === 'directa_admin'
                ? 'aprobado'
                : 'pendiente';

            $requiereAprobacion = $data['modo_aprobacion'] === 'flujo_aprobadores'
                ? 1
                : 0;

            $minutosPagables = ! empty($data['impacta_prenomina'])
                ? (int) $data['minutos_aprobados']
                : 0;

            $idEvento = DB::connection('portal_main')
                ->table('calendario_eventos')
                ->insertGetId([
                    'id_usuario'          => $data['id_usuario'],
                    'id_empleado'         => $employee->id,
                    'id_portal'           => $data['id_portal'],
                    'id_cliente'          => $data['id_cliente'],
                    'inicio'              => $data['fecha'],
                    'fin'                 => $data['fecha'],
                    'dias_evento'         => 1,
                    'descripcion'         => $data['descripcion'] ?? null,
                    'id_tipo'             => self::TIPO_HORAS_EXTRA,
                    'estado'              => 2,
                    'estado_aprobacion'   => $estadoAprobacion,
                    'origen_evento'       => $data['origen_evento'] ?? 'manual',
                    'requiere_aprobacion' => $requiereAprobacion,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

            DB::connection('portal_main')
                ->table('checador_evento_detalles')
                ->insert([
                    'id_evento'                      => $idEvento,
                    'tipo_operativo'                 => 'horas_extra',
                    'fecha'                          => $data['fecha'],
                    'hora_inicio'                    => $data['hora_inicio'],
                    'hora_fin'                       => $data['hora_fin'],
                    'minutos_detectados'             => (int) $data['minutos_aprobados'],
                    'minutos_aprobados'              => $data['modo_aprobacion'] === 'directa_admin'
                        ? (int) $data['minutos_aprobados']
                        : 0,
                    'minutos_pagables'               => $data['modo_aprobacion'] === 'directa_admin'
                        ? $minutosPagables
                        : 0,
                    'modo_aprobacion'                => $data['modo_aprobacion'],
                    'requiere_confirmacion_empleado' => 1,
                    'confirmacion_empleado_estado'   => 'pendiente',
                    'confirmado_por_empleado_id'     => null,
                    'confirmado_at'                  => null,
                    'confirmacion_comentario'        => null,
                    'aprobado_por_tipo'              => $data['modo_aprobacion'] === 'directa_admin'
                        ? ($data['aprobado_por_tipo'] ?? 'admin')
                        : null,
                    'aprobado_por_id'                => $data['modo_aprobacion'] === 'directa_admin'
                        ? $data['id_usuario']
                        : null,
                    'aprobado_at'                    => $data['modo_aprobacion'] === 'directa_admin'
                        ? now()
                        : null,
                    'impacta_asistencia'             => 1,
                    'impacta_prenomina'              => ! empty($data['impacta_prenomina']) ? 1 : 0,
                    'visible_analisis'               => 1,
                    'observaciones'                  => $data['descripcion'] ?? null,
                    'metadata'                       => json_encode([
                        'registrado_por'             => $data['registrado_por'] ?? 'admin',
                        'registrado_desde'           => $data['registrado_desde'] ?? 'comunicacion360',
                        'registrado_por_empleado_id' => $data['registrado_por_empleado_id'] ?? null,
                    ]),
                    'created_at'                     => now(),
                    'updated_at'                     => now(),
                ]);

            $aprobadoresCreados = 0;

            if ($data['modo_aprobacion'] === 'flujo_aprobadores') {
                $asignacion = DB::connection('portal_main')
                    ->table('checador_asignaciones')
                    ->where('id_portal', $data['id_portal'])
                    ->where('id_cliente', $data['id_cliente'])
                    ->where('id_empleado', $employee->id)
                    ->where('activa', 1)
                    ->whereDate('fecha_inicio', '<=', $data['fecha'])
                    ->where(function ($query) use ($data) {
                        $query->whereNull('fecha_fin')
                            ->orWhereDate('fecha_fin', '>=', $data['fecha']);
                    })
                    ->orderByDesc('prioridad')
                    ->orderByDesc('id')
                    ->first();

                if (! $asignacion) {
                    throw new \Exception('El empleado no tiene plantilla activa para la fecha del evento.');
                }

                $aprobadores = DB::connection('portal_main')
                    ->table('checador_checada_plantilla_aprobadores')
                    ->where('id_plantilla', $asignacion->id_plantilla_checada)
                    ->where('tipo_evento_id', self::TIPO_HORAS_EXTRA)
                    ->where('activo', 1)
                    ->orderBy('nivel')
                    ->orderBy('id')
                    ->get();

                if ($aprobadores->isEmpty()) {
                    throw new \Exception('No hay aprobadores configurados para horas extra en la plantilla del empleado.');
                }

                foreach ($aprobadores as $aprobador) {
                    $autoAprobar = ! empty($data['auto_aprobar_solicitante'])
                    && ! empty($data['solicitante_empleado_id'])
                    && (int) $aprobador->id_empleado_aprobador === (int) $data['solicitante_empleado_id'];

                    DB::connection('portal_main')
                        ->table('checador_evento_aprobaciones')
                        ->insert([
                            'id_portal'               => $data['id_portal'],
                            'id_cliente'              => $data['id_cliente'],
                            'id_evento'               => $idEvento,
                            'tipo_evento_id'          => self::TIPO_HORAS_EXTRA,
                            'id_empleado_solicitante' => $employee->id,
                            'id_empleado_aprobador'   => $aprobador->id_empleado_aprobador,
                            'nivel'                   => $aprobador->nivel,
                            'estatus'                 => $autoAprobar ? 'aprobado' : 'pendiente',
                            'comentario'              => $autoAprobar
                                ? 'system.auto_approved_by_requesting_approver'
                                : null,
                            'fecha_respuesta'         => $autoAprobar ? now() : null,
                            'created_at'              => now(),
                            'updated_at'              => now(),
                        ]);

                    $aprobadoresCreados++;
                }

                $estadoAprobacion = $this->recalcularDespuesDeCrearFlujo(
                    $idEvento,
                    $data,
                    $minutosPagables
                );
            }

            return [
                'evento_id'           => $idEvento,
                'estado_aprobacion'   => $estadoAprobacion,
                'aprobadores_creados' => $aprobadoresCreados,
            ];
        });
    }

    private function recalcularDespuesDeCrearFlujo(
        int $idEvento,
        array $data,
        int $minutosPagables
    ): string {
        $aprobaciones = DB::connection('portal_main')
            ->table('checador_evento_aprobaciones')
            ->where('id_evento', $idEvento)
            ->get();

        $hayRechazada = $aprobaciones->contains(function ($item) {
            return $item->estatus === 'rechazado';
        });

        $hayPendiente = $aprobaciones->contains(function ($item) {
            return $item->estatus === 'pendiente';
        });

        if ($hayRechazada) {
            DB::connection('portal_main')
                ->table('calendario_eventos')
                ->where('id', $idEvento)
                ->update([
                    'estado_aprobacion' => 'rechazado',
                    'estado'            => 3,
                    'updated_at'        => now(),
                ]);

            DB::connection('portal_main')
                ->table('checador_evento_detalles')
                ->where('id_evento', $idEvento)
                ->update([
                    'minutos_aprobados' => 0,
                    'minutos_pagables'  => 0,
                    'aprobado_por_tipo' => null,
                    'aprobado_por_id'   => null,
                    'aprobado_at'       => null,
                    'updated_at'        => now(),
                ]);

            return 'rechazado';
        }

        if (! $hayPendiente) {
            DB::connection('portal_main')
                ->table('calendario_eventos')
                ->where('id', $idEvento)
                ->update([
                    'estado_aprobacion' => 'aprobado',
                    'estado'            => 2,
                    'updated_at'        => now(),
                ]);

            DB::connection('portal_main')
                ->table('checador_evento_detalles')
                ->where('id_evento', $idEvento)
                ->update([
                    'minutos_aprobados' => (int) $data['minutos_aprobados'],
                    'minutos_pagables'  => $minutosPagables,
                    'aprobado_por_tipo' => 'empleado_aprobador',
                    'aprobado_por_id'   => $data['solicitante_empleado_id'] ?? null,
                    'aprobado_at'       => now(),
                    'updated_at'        => now(),
                ]);

            return 'aprobado';
        }

        return 'pendiente';
    }
}
