<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Services\ChecadorHorasExtraService;
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

        try {
            $data['registrado_por']   = 'admin';
            $data['registrado_desde'] = 'comunicacion360';
            $data['origen_evento']    = 'manual';

            $resultado = app(ChecadorHorasExtraService::class)
                ->registrar($data, $employee);
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
            'estado_aprobacion'   => $resultado['estado_aprobacion'],
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
            ->table('checador_evento_aprobaciones as a')
            ->leftJoin('empleados as ap', 'ap.id', '=', 'a.id_empleado_aprobador')
            ->whereIn('a.id_evento', $eventos->pluck('id')->values())
            ->orderBy('a.nivel')
            ->orderBy('a.id')
            ->select([
                'a.*',
                'ap.id_empleado as aprobador_clave',
                'ap.nombre as aprobador_nombre',
                'ap.paterno as aprobador_paterno',
                'ap.materno as aprobador_materno',
            ])
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
                                'aprobador'       => [
                                    'id'     => $aprobacion->id_empleado_aprobador,
                                    'clave'  => $aprobacion->aprobador_clave,
                                    'nombre' => trim(collect([
                                        $aprobacion->aprobador_nombre,
                                        $aprobacion->aprobador_paterno,
                                        $aprobacion->aprobador_materno,
                                    ])->filter()->implode(' ')),
                                ],
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
