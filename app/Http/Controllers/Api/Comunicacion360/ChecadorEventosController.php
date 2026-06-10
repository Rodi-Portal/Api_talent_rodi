<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ChecadorEventosController extends Controller
{
    private const TIPO_HORAS_EXTRA = 9;

    public function registrarHorasExtra(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'         => ['required', 'integer'],
            'id_cliente'        => ['required', 'integer'],
            'id_usuario'        => ['required', 'integer'],

            'fecha'             => ['required', 'date_format:Y-m-d'],
            'hora_inicio'       => ['required', 'date_format:H:i'],
            'hora_fin'          => ['required', 'date_format:H:i'],

            'minutos_aprobados' => ['required', 'integer', 'min:1'],
            'impacta_prenomina' => ['required', 'boolean'],

            'modo_aprobacion'   => ['required', 'in:flujo_aprobadores,directa_admin'],
            'descripcion'       => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $employee = DB::connection('portal_main')
            ->table('empleados')
            ->where('id', $id)
            ->where('id_portal', $data['id_portal'])
            ->where('id_cliente', $data['id_cliente'])
            ->first();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no encontrado para el portal y cliente enviados.',
            ], 404);
        }

        $estadoAprobacion = $data['modo_aprobacion'] === 'directa_admin'
            ? 'aprobado'
            : 'pendiente';

        $requiereAprobacion = $data['modo_aprobacion'] === 'flujo_aprobadores'
            ? 1
            : 0;
        try {
            $resultado = DB::connection('portal_main')->transaction(function () use ($data, $employee, $estadoAprobacion, $requiereAprobacion) {
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
                        'origen_evento'       => 'manual',
                        'requiere_aprobacion' => $requiereAprobacion,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);

                $minutosPagables = $data['impacta_prenomina']
                    ? (int) $data['minutos_aprobados']
                    : 0;

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

                        'confirmacion_empleado_estado'   =>
                        $data['modo_aprobacion'] === 'directa_admin'
                            ? 'pendiente'
                            : 'pendiente',

                        'confirmado_por_empleado_id'     => null,
                        'confirmado_at'                  => null,
                        'confirmacion_comentario'        => null,
                        'aprobado_por_tipo'              => $data['modo_aprobacion'] === 'directa_admin' ? 'admin' : null,
                        'aprobado_por_id'                => $data['modo_aprobacion'] === 'directa_admin' ? $data['id_usuario'] : null,
                        'aprobado_at'                    => $data['modo_aprobacion'] === 'directa_admin' ? now() : null,
                        'impacta_asistencia'             => 1,
                        'impacta_prenomina'              => $data['impacta_prenomina'] ? 1 : 0,
                        'visible_analisis'               => 1,
                        'observaciones'                  => $data['descripcion'] ?? null,
                        'metadata'                       => json_encode([
                            'registrado_por'   => 'admin',
                            'registrado_desde' => 'comunicacion360',
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
                                'estatus'                 => 'pendiente',
                                'comentario'              => null,
                                'fecha_respuesta'         => null,
                                'created_at'              => now(),
                                'updated_at'              => now(),
                            ]);

                        $aprobadoresCreados++;
                    }
                }

                return [
                    'evento_id'           => $idEvento,
                    'aprobadores_creados' => $aprobadoresCreados,
                ];
            });
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }
        return response()->json([
            'ok'                  => true,
            'message'             => 'Evento de horas extra creado correctamente.',
            'evento_id'           => $resultado['evento_id'],
            'estado_aprobacion'   => $estadoAprobacion,
            'aprobadores_creados' => $resultado['aprobadores_creados'],
        ]);
    }
    public function registrarHorasExtraMasivo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'         => ['required', 'integer'],
            'id_cliente'        => ['required', 'integer'],
            'id_usuario'        => ['required', 'integer'],
            'empleados'         => ['required', 'array', 'min:1'],
            'empleados.*'       => ['required', 'integer'],

            'fecha'             => ['required', 'date_format:Y-m-d'],
            'hora_inicio'       => ['required', 'date_format:H:i'],
            'hora_fin'          => ['required', 'date_format:H:i'],

            'minutos_aprobados' => ['required', 'integer', 'min:1'],
            'impacta_prenomina' => ['required', 'boolean'],
            'modo_aprobacion'   => ['required', 'in:flujo_aprobadores,directa_admin'],
            'descripcion'       => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $resultados = [];

        foreach ($data['empleados'] as $empleadoId) {
            $fakeRequest = new Request(collect($data)->except('empleados')->toArray());

            $response = $this->registrarHorasExtra($fakeRequest, (int) $empleadoId);
            $payload  = $response->getData(true);

            $resultados[] = [
                'id_empleado'         => (int) $empleadoId,
                'ok'                  => (bool) ($payload['ok'] ?? false),
                'evento_id'           => $payload['evento_id'] ?? null,
                'estado_aprobacion'   => $payload['estado_aprobacion'] ?? null,
                'aprobadores_creados' => $payload['aprobadores_creados'] ?? 0,
                'message'             => $payload['message'] ?? null,
            ];
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Proceso masivo terminado.',
            'data'    => [
                'total'    => count($resultados),
                'exitosos' => collect($resultados)->where('ok', true)->count(),
                'fallidos' => collect($resultados)->where('ok', false)->count(),
                'detalle'  => $resultados,
            ],
        ]);
    }

    public function eventosEmpleado(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal'         => ['required', 'integer'],
            'id_cliente'        => ['nullable', 'integer'],
            'estado_aprobacion' => ['nullable', 'in:pendiente,aprobado,rechazado,cancelado,todos'],
        ]);

        $query = DB::connection('portal_main')
            ->table('calendario_eventos as ce')
            ->leftJoin('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->leftJoin('checador_evento_detalles as ced', 'ced.id_evento', '=', 'ce.id')
            ->where('ce.id_portal', $data['id_portal'])
            ->where('ce.id_empleado', $id)
            ->where('ce.eliminado', 0);

        if (! empty($data['id_cliente'])) {
            $query->where('ce.id_cliente', $data['id_cliente']);
        }

        if (
            ! empty($data['estado_aprobacion']) &&
            $data['estado_aprobacion'] !== 'todos'
        ) {
            $query->where('ce.estado_aprobacion', $data['estado_aprobacion']);
        }

        $eventos = $query
            ->select([
                'ce.id',
                'ce.id_tipo',
                'ce.inicio',
                'ce.fin',
                'ce.dias_evento',
                'ce.descripcion',
                'ce.estado',
                'ce.estado_aprobacion',
                'ce.origen_evento',
                'ce.requiere_aprobacion',
                'ce.created_at',

                'eo.name as tipo_nombre',
                'eo.color as tipo_color',

                'ced.tipo_operativo',
                'ced.fecha',
                'ced.hora_inicio',
                'ced.hora_fin',
                'ced.minutos_detectados',
                'ced.minutos_aprobados',
                'ced.minutos_pagables',
                'ced.modo_aprobacion',
                'ced.impacta_asistencia',
                'ced.impacta_prenomina',
                'ced.visible_analisis',
                'ced.requiere_confirmacion_empleado',
                'ced.confirmacion_empleado_estado',
                'ced.confirmado_por_empleado_id',
                'ced.confirmado_at',
                'ced.confirmacion_comentario',
            ])
            ->orderByDesc('ce.created_at')
            ->limit(100)
            ->get();

        $aprobacionesPorEvento = DB::connection('portal_main')
            ->table('checador_evento_aprobaciones')
            ->whereIn('id_evento', $eventos->pluck('id')->values())
            ->orderBy('nivel')
            ->orderBy('id')
            ->get()
            ->groupBy('id_evento');

        return response()->json([
            'ok'   => true,
            'data' => $eventos->map(function ($item) use ($aprobacionesPorEvento) {
                return [
                    'id'                  => $item->id,
                    'tipo_evento_id'      => $item->id_tipo,
                    'tipo_evento'         => [
                        'nombre' => $item->tipo_nombre,
                        'color'  => $item->tipo_color,
                    ],
                    'periodo'             => [
                        'inicio' => $item->inicio,
                        'fin'    => $item->fin,
                        'dias'   => $item->dias_evento,
                    ],
                    'descripcion'         => $item->descripcion,
                    'estado'              => $item->estado,
                    'estado_aprobacion'   => $item->estado_aprobacion,
                    'origen_evento'       => $item->origen_evento,
                    'requiere_aprobacion' => (bool) $item->requiere_aprobacion,
                    'creado_en'           => $item->created_at,

                    'detalle'             => [
                        'tipo_operativo'     => $item->tipo_operativo,
                        'fecha'              => $item->fecha,
                        'hora_inicio'        => $item->hora_inicio,
                        'hora_fin'           => $item->hora_fin,
                        'minutos_detectados' => $item->minutos_detectados,
                        'minutos_aprobados'  => $item->minutos_aprobados,
                        'minutos_pagables'   => $item->minutos_pagables,
                        'modo_aprobacion'    => $item->modo_aprobacion,
                        'impacta_asistencia' => (bool) $item->impacta_asistencia,
                        'impacta_prenomina'  => (bool) $item->impacta_prenomina,
                        'visible_analisis'   => (bool) $item->visible_analisis,
                        'confirmacion'       => [
                            'requiere'    => (bool) $item->requiere_confirmacion_empleado,
                            'estado'      => $item->confirmacion_empleado_estado,
                            'empleado_id' => $item->confirmado_por_empleado_id,
                            'fecha'       => $item->confirmado_at,
                            'comentario'  => $item->confirmacion_comentario,
                        ],
                    ],

                    'aprobaciones'        => ($aprobacionesPorEvento[$item->id] ?? collect())
                        ->map(function ($aprobacion) {
                            return [
                                'id'              => $aprobacion->id,
                                'aprobador_id'    => $aprobacion->id_empleado_aprobador,
                                'nivel'           => $aprobacion->nivel,
                                'estatus'         => $aprobacion->estatus,
                                'comentario'      => $aprobacion->comentario,
                                'fecha_respuesta' => $aprobacion->fecha_respuesta,
                            ];
                        })
                        ->values(),
                ];
            })->values(),
        ]);
    }
}
