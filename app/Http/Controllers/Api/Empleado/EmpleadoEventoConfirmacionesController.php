<?php

namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmpleadoEventoConfirmacionesController extends Controller
{
    public function pendientes(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $items = DB::connection($conn)
            ->table('checador_evento_detalles as d')
            ->join('calendario_eventos as ce', 'ce.id', '=', 'd.id_evento')
            ->leftJoin('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->leftJoin('empleados as aprobador', 'aprobador.id', '=', 'd.aprobado_por_id')
            ->where('ce.id_empleado', (int) $employee->id)
            ->where('ce.eliminado', 0)
            ->where('ce.estado_aprobacion', 'aprobado')
            ->where('d.tipo_operativo', 'horas_extra')
            ->where('d.requiere_confirmacion_empleado', 1)
            ->where('d.confirmacion_empleado_estado', 'pendiente')
            ->where('d.minutos_aprobados', '>', 0)
            ->select([
                'ce.id as evento_id',
                'ce.id_tipo',
                'ce.inicio',
                'ce.fin',
                'ce.dias_evento',
                'ce.descripcion',
                'ce.estado_aprobacion',
                'ce.origen_evento',

                'eo.name as tipo_nombre',
                'eo.color as tipo_color',

                'd.id as detalle_id',
                'd.fecha',
                'd.hora_inicio',
                'd.hora_fin',
                'd.minutos_detectados',
                'd.minutos_aprobados',
                'd.minutos_pagables',
                'd.modo_aprobacion',
                'd.aprobado_por_tipo',
                'd.aprobado_por_id',
                'd.aprobado_at',
                'd.confirmacion_empleado_estado',
                'd.observaciones',

                'aprobador.id as aprobador_id',
                'aprobador.id_empleado as aprobador_clave',
                'aprobador.nombre as aprobador_nombre',
                'aprobador.paterno as aprobador_paterno',
                'aprobador.materno as aprobador_materno',
            ])
            ->orderByDesc('d.fecha')
            ->orderByDesc('d.created_at')
            ->get()
            ->map(function ($item) {
                return [
                    'evento_id' => $item->evento_id,
                    'detalle_id' => $item->detalle_id,

                    'tipo_evento' => [
                        'id' => $item->id_tipo,
                        'nombre' => $item->tipo_nombre,
                        'color' => $item->tipo_color,
                    ],

                    'periodo' => [
                        'inicio' => $item->inicio,
                        'fin' => $item->fin,
                        'dias' => $item->dias_evento,
                        'fecha' => $item->fecha,
                        'hora_inicio' => $item->hora_inicio,
                        'hora_fin' => $item->hora_fin,
                    ],

                    'descripcion' => $item->descripcion,
                    'observaciones' => $item->observaciones,

                    'minutos' => [
                        'detectados' => (int) $item->minutos_detectados,
                        'aprobados' => (int) $item->minutos_aprobados,
                        'pagables' => (int) $item->minutos_pagables,
                    ],

                    'aprobacion' => [
                        'estado' => $item->estado_aprobacion,
                        'modo' => $item->modo_aprobacion,
                        'aprobado_por_tipo' => $item->aprobado_por_tipo,
                        'aprobado_at' => $item->aprobado_at,
                        'aprobador' => [
                            'id' => $item->aprobador_id,
                            'clave' => $item->aprobador_clave,
                            'nombre' => trim(collect([
                                $item->aprobador_nombre,
                                $item->aprobador_paterno,
                                $item->aprobador_materno,
                            ])->filter()->implode(' ')),
                        ],
                    ],

                    'confirmacion' => [
                        'estado' => $item->confirmacion_empleado_estado,
                    ],

                    'origen_evento' => $item->origen_evento,
                ];
            });

        return response()->json([
            'ok' => true,
            'data' => $items,
        ]);
    }

    public function confirmar(Request $request, int $id)
    {
        return $this->responderConfirmacion($request, $id, 'confirmado');
    }

    public function rechazar(Request $request, int $id)
    {
        $request->validate([
            'comentario' => 'required|string|max:1000',
        ]);

        return $this->responderConfirmacion($request, $id, 'rechazado');
    }

    private function responderConfirmacion(Request $request, int $eventoId, string $estado)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        DB::connection($conn)->beginTransaction();

        try {
            $detalle = DB::connection($conn)
                ->table('checador_evento_detalles as d')
                ->join('calendario_eventos as ce', 'ce.id', '=', 'd.id_evento')
                ->where('d.id_evento', $eventoId)
                ->where('ce.id_empleado', (int) $employee->id)
                ->where('ce.eliminado', 0)
                ->where('ce.estado_aprobacion', 'aprobado')
                ->where('d.tipo_operativo', 'horas_extra')
                ->where('d.requiere_confirmacion_empleado', 1)
                ->where('d.confirmacion_empleado_estado', 'pendiente')
                ->select('d.*')
                ->lockForUpdate()
                ->first();

            if (! $detalle) {
                DB::connection($conn)->rollBack();

                return response()->json([
                    'ok' => false,
                    'message' => 'No se encontró una hora extra pendiente de confirmación.',
                ], 404);
            }

            DB::connection($conn)
                ->table('checador_evento_detalles')
                ->where('id', $detalle->id)
                ->update([
                    'confirmacion_empleado_estado' => $estado,
                    'confirmado_por_empleado_id' => (int) $employee->id,
                    'confirmado_at' => now(),
                    'confirmacion_comentario' => $estado === 'rechazado'
                        ? $request->input('comentario')
                        : null,
                    'updated_at' => now(),
                ]);

            DB::connection($conn)->commit();

            return response()->json([
                'ok' => true,
                'message' => $estado === 'confirmado'
                    ? 'Horas extra confirmadas correctamente.'
                    : 'Horas extra rechazadas correctamente.',
            ]);
        } catch (\Throwable $e) {
            DB::connection($conn)->rollBack();

            return response()->json([
                'ok' => false,
                'message' => 'No fue posible responder la confirmación.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}