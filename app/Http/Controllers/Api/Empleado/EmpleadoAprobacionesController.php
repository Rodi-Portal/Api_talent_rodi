<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmpleadoAprobacionesController extends Controller
{
    public function pendientes(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $pendientes = DB::connection($conn)
            ->table('checador_evento_aprobaciones as a')
            ->join('calendario_eventos as ce', 'ce.id', '=', 'a.id_evento')
            ->join('eventos_option as eo', 'eo.id', '=', 'a.tipo_evento_id')
            ->leftJoin('empleados as s', 's.id', '=', 'a.id_empleado_solicitante')
            ->where('a.id_empleado_aprobador', (int) $employee->id)
            ->where('a.estatus', 'pendiente')
            ->where('ce.eliminado', 0)
            ->where('ce.estado_aprobacion', 'pendiente')
            ->select([
                'a.id as aprobacion_id',
                'a.id_evento',
                'a.tipo_evento_id',
                'a.nivel',
                'a.estatus',
                'a.created_at',

                'ce.inicio',
                'ce.fin',
                'ce.dias_evento',
                'ce.descripcion',
                'ce.archivo',
                'ce.estado_aprobacion',
                'ce.origen_evento',

                'eo.name as evento_nombre',
                'eo.color as evento_color',

                's.id as solicitante_id',
                's.id_empleado as solicitante_clave',
                's.nombre as solicitante_nombre',
                's.paterno as solicitante_paterno',
                's.materno as solicitante_materno',
            ])
            ->orderBy('a.created_at', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'aprobacion_id'     => $item->aprobacion_id,
                    'evento_id'         => $item->id_evento,
                    'tipo_evento_id'    => $item->tipo_evento_id,
                    'tipo_evento'       => [
                        'nombre' => $item->evento_nombre,
                        'color'  => $item->evento_color,
                    ],
                    'solicitante'       => [
                        'id'     => $item->solicitante_id,
                        'clave'  => $item->solicitante_clave,
                        'nombre' => trim(collect([
                            $item->solicitante_nombre,
                            $item->solicitante_paterno,
                            $item->solicitante_materno,
                        ])->filter()->implode(' ')),
                    ],
                    'periodo'           => [
                        'inicio' => $item->inicio,
                        'fin'    => $item->fin,
                        'dias'   => $item->dias_evento,
                    ],
                    'comentario'        => $item->descripcion,
                    'archivo'           => $item->archivo,
                    'nivel'             => $item->nivel,
                    'estatus'           => $item->estatus,
                    'estado_aprobacion' => $item->estado_aprobacion,
                    'origen_evento'     => $item->origen_evento,
                    'creado_en'         => $item->created_at,
                ];
            });

        return response()->json([
            'ok'   => true,
            'data' => $pendientes,
        ]);
    }
    public function aprobar(Request $request, int $id)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'comentario' => 'nullable|string|max:1000',
        ]);

        DB::connection($conn)->beginTransaction();

        try {
            $aprobacion = DB::connection($conn)
                ->table('checador_evento_aprobaciones')
                ->where('id', $id)
                ->where('id_empleado_aprobador', (int) $employee->id)
                ->lockForUpdate()
                ->first();

            if (! $aprobacion) {
                DB::connection($conn)->rollBack();

                return response()->json([
                    'ok'      => false,
                    'message' => 'Aprobación no encontrada.',
                ], 404);
            }

            if ($aprobacion->estatus !== 'pendiente') {
                DB::connection($conn)->rollBack();

                return response()->json([
                    'ok'      => false,
                    'message' => 'Esta solicitud ya fue respondida.',
                ], 422);
            }

            DB::connection($conn)
                ->table('checador_evento_aprobaciones')
                ->where('id', $aprobacion->id)
                ->update([
                    'estatus'         => 'aprobado',
                    'comentario'      => $data['comentario'] ?? null,
                    'fecha_respuesta' => now(),
                    'updated_at'      => now(),
                ]);

            $this->recalcularEstadoEvento((int) $aprobacion->id_evento);

            DB::connection($conn)->commit();

            return response()->json([
                'ok'      => true,
                'message' => 'Solicitud aprobada correctamente.',
            ]);

        } catch (\Throwable $e) {
            DB::connection($conn)->rollBack();

            return response()->json([
                'ok'      => false,
                'message' => 'No fue posible aprobar la solicitud.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    private function recalcularEstadoEvento(int $eventoId): void
    {
        $conn = 'portal_main';

        $aprobaciones = DB::connection($conn)
            ->table('checador_evento_aprobaciones')
            ->where('id_evento', $eventoId)
            ->get();

        if ($aprobaciones->isEmpty()) {
            return;
        }

        $hayRechazada = $aprobaciones->contains(function ($item) {
            return $item->estatus === 'rechazado';
        });

        $hayPendiente = $aprobaciones->contains(function ($item) {
            return $item->estatus === 'pendiente';
        });

        if ($hayRechazada) {
            $estadoAprobacion = 'rechazado';
            $estadoOperativo  = 3;
        } elseif (! $hayPendiente) {
            $estadoAprobacion = 'aprobado';
            $estadoOperativo  = 2;
        } else {
            $estadoAprobacion = 'pendiente';
            $estadoOperativo  = 1;
        }

        DB::connection($conn)
            ->table('calendario_eventos')
            ->where('id', $eventoId)
            ->update([
                'estado_aprobacion' => $estadoAprobacion,
                'estado'            => $estadoOperativo,
                'updated_at'        => now(),
            ]);
    }
    public function rechazar(Request $request, int $id)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'comentario' => 'required|string|max:1000',
        ]);

        DB::connection($conn)->beginTransaction();

        try {
            $aprobacion = DB::connection($conn)
                ->table('checador_evento_aprobaciones')
                ->where('id', $id)
                ->where('id_empleado_aprobador', (int) $employee->id)
                ->lockForUpdate()
                ->first();

            if (! $aprobacion) {
                DB::connection($conn)->rollBack();

                return response()->json([
                    'ok'      => false,
                    'message' => 'Aprobación no encontrada.',
                ], 404);
            }

            if ($aprobacion->estatus !== 'pendiente') {
                DB::connection($conn)->rollBack();

                return response()->json([
                    'ok'      => false,
                    'message' => 'Esta solicitud ya fue respondida.',
                ], 422);
            }

            DB::connection($conn)
                ->table('checador_evento_aprobaciones')
                ->where('id', $aprobacion->id)
                ->update([
                    'estatus'         => 'rechazado',
                    'comentario'      => $data['comentario'],
                    'fecha_respuesta' => now(),
                    'updated_at'      => now(),
                ]);

            $this->recalcularEstadoEvento((int) $aprobacion->id_evento);

            DB::connection($conn)->commit();

            return response()->json([
                'ok'      => true,
                'message' => 'Solicitud rechazada correctamente.',
            ]);

        } catch (\Throwable $e) {
            DB::connection($conn)->rollBack();

            return response()->json([
                'ok'      => false,
                'message' => 'No fue posible rechazar la solicitud.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function historial(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'fecha_inicio'   => 'nullable|date',
            'fecha_fin'      => 'nullable|date|after_or_equal:fecha_inicio',
            'estatus'        => 'nullable|in:aprobado,rechazado,todos',
            'tipo_evento_id' => 'nullable|integer|exists:portal_main.eventos_option,id',
        ]);

        $query = DB::connection($conn)
            ->table('checador_evento_aprobaciones as cea')
            ->join('calendario_eventos as ce', 'ce.id', '=', 'cea.id_evento')
            ->leftJoin('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->leftJoin('empleados as e', 'e.id', '=', 'ce.id_empleado')
            ->where('cea.id_empleado_aprobador', (int) $employee->id)
            ->whereIn('cea.estatus', ['aprobado', 'rechazado'])
            ->where('ce.eliminado', 0);

        if (! empty($data['fecha_inicio'])) {
            $query->whereDate('ce.inicio', '>=', $data['fecha_inicio']);
        }

        if (! empty($data['fecha_fin'])) {
            $query->whereDate('ce.fin', '<=', $data['fecha_fin']);
        }

        if (! empty($data['estatus']) && $data['estatus'] !== 'todos') {
            $query->where('cea.estatus', $data['estatus']);
        }

        if (! empty($data['tipo_evento_id'])) {
            $query->where('ce.id_tipo', (int) $data['tipo_evento_id']);
        }

        $items = $query
            ->orderByDesc('cea.fecha_respuesta')
            ->orderByDesc('cea.updated_at')
            ->select([
                'cea.id',
                'cea.estatus',
                'cea.nivel',
                'cea.comentario',
                'cea.fecha_respuesta',

                'ce.id as evento_id',
                'ce.id_tipo',
                'ce.inicio',
                'ce.fin',
                'ce.dias_evento',
                'ce.descripcion',
                'ce.archivo',
                'ce.estado_aprobacion',
                'ce.origen_evento',

                'eo.name as tipo_nombre',
                'eo.color as tipo_color',

                'e.id as empleado_id',
                'e.id_empleado as empleado_clave',
                'e.nombre',
                'e.paterno',
                'e.materno',
            ])
            ->get();

        $response = $items->map(function ($item) {
            return [
                'aprobacion_id'     => $item->id,
                'evento_id'         => $item->evento_id,
                'tipo_evento_id'    => $item->id_tipo,

                'tipo_evento'       => [
                    'nombre' => $item->tipo_nombre,
                    'color'  => $item->tipo_color,
                ],

                'solicitante'       => [
                    'id'     => $item->empleado_id,
                    'clave'  => $item->empleado_clave,
                    'nombre' => trim(collect([
                        $item->nombre,
                        $item->paterno,
                        $item->materno,
                    ])->filter()->join(' ')),
                ],

                'periodo'           => [
                    'inicio' => $item->inicio,
                    'fin'    => $item->fin,
                    'dias'   => $item->dias_evento,
                ],

                'comentario'        => $item->descripcion,
                'archivo'           => $item->archivo,
                'nivel'             => $item->nivel,
                'estatus'           => $item->estatus,
                'estado_aprobacion' => $item->estado_aprobacion,
                'origen_evento'     => $item->origen_evento,

                'respuesta'         => [
                    'comentario' => $item->comentario,
                    'fecha'      => $item->fecha_respuesta,
                ],
            ];
        });

        return response()->json([
            'ok'      => true,
            'data'    => $response,
            'filters' => [
                'fecha_inicio'   => $data['fecha_inicio'] ?? null,
                'fecha_fin'      => $data['fecha_fin'] ?? null,
                'estatus'        => $data['estatus'] ?? 'todos',
                'tipo_evento_id' => $data['tipo_evento_id'] ?? null,
            ],
        ]);
    }
    public function resumen(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $pendingCount = DB::connection($conn)
            ->table('checador_evento_aprobaciones as a')
            ->join('calendario_eventos as ce', 'ce.id', '=', 'a.id_evento')
            ->where('a.id_empleado_aprobador', (int) $employee->id)
            ->where('a.estatus', 'pendiente')
            ->where('ce.eliminado', 0)
            ->where('ce.estado_aprobacion', 'pendiente')
            ->count();

        $historyCount = DB::connection($conn)
            ->table('checador_evento_aprobaciones as a')
            ->join('calendario_eventos as ce', 'ce.id', '=', 'a.id_evento')
            ->where('a.id_empleado_aprobador', (int) $employee->id)
            ->whereIn('a.estatus', ['aprobado', 'rechazado'])
            ->where('ce.eliminado', 0)
            ->count();
        $myRequestsCount = DB::connection($conn)
            ->table('calendario_eventos')
            ->where('id_empleado', (int) $employee->id)
            ->where('eliminado', 0)
            ->where('origen_evento', 'checador')
            ->where('requiere_aprobacion', 1)
            ->count();

        return response()->json([
            'ok'   => true,
            'data' => [
                'can_approve'       => ($pendingCount + $historyCount) > 0,
                'pending_count'     => $pendingCount,
                'history_count'     => $historyCount,
                'my_requests_count' => $myRequestsCount,
            ],
        ]);
    }

    public function misSolicitudes(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $items = DB::connection($conn)
            ->table('calendario_eventos as ce')
            ->leftJoin('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->where('ce.id_empleado', (int) $employee->id)
            ->where('ce.eliminado', 0)
            ->where('ce.origen_evento', 'checador')
            ->where('ce.requiere_aprobacion', 1)
            ->whereIn('ce.estado_aprobacion', [
                'pendiente',
                'aprobado',
                'rechazado',
            ])
            ->select([
                'ce.id',
                'ce.id_tipo',
                'ce.inicio',
                'ce.fin',
                'ce.dias_evento',
                'ce.descripcion',
                'ce.archivo',
                'ce.estado_aprobacion',
                'ce.origen_evento',
                'ce.created_at',

                'eo.name as tipo_nombre',
                'eo.color as tipo_color',
            ])
            ->orderByDesc('ce.created_at')
            ->get();

        $response = $items->map(function ($item) {
            return [
                'id'             => $item->id,

                'tipo_evento_id' => $item->id_tipo,

                'tipo_evento'    => [
                    'nombre' => $item->tipo_nombre,
                    'color'  => $item->tipo_color,
                ],

                'solicitante'    => null,

                'periodo'        => [
                    'inicio' => $item->inicio,
                    'fin'    => $item->fin,
                    'dias'   => $item->dias_evento,
                ],

                'comentario'     => $item->descripcion,
                'archivo'        => $item->archivo,

                'estatus'        => $item->estado_aprobacion ?? 'pendiente',

                'origen_evento'  => $item->origen_evento,

                'creado_en'      => $item->created_at,
            ];
        });

        return response()->json([
            'ok'   => true,
            'data' => $response,
        ]);
    }
}
