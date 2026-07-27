<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Services\Checador\AttendanceAdministrationCommandService;
use App\Services\Checador\AttendanceAdministrationService;
use App\Services\Checador\AttendanceDayContextService;
use App\Services\Checador\JornadaCalculoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AccesosChecadorGestionController extends Controller
{
    public function contextoDia(Request $request, $id)
    {
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $administrador = $request->user();

        $empleado = $this->findAuthorizedEmployee(
            $request,
            (int) $id
        );

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'code'    => 'EMPLOYEE_NOT_FOUND',
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        $idPortal  = (int) $administrador->id_portal;
        $idCliente = (int) $empleado->id_cliente;
        $fecha     = $validated['fecha'] ?? now()->toDateString();

        $contexto = app(AttendanceDayContextService::class)->resolver(
            $idPortal,
            (int) $empleado->id,
            $fecha,
            $idCliente
        );
        $asignacion       = $contexto['asignacion'];
        $plantillaHorario = $contexto['plantillaHorario'];
        $detalleHorario   = $contexto['detalleHorario'];
        $ventanaOperativa = $contexto['ventanaOperativa'];
        $checadas         = $contexto['checadas'];
        $alertas          = [];
        $jornada          = null;

        if ($plantillaHorario && $detalleHorario && (int) $detalleHorario->labora === 1) {
            $jornada = app(JornadaCalculoService::class)->calcularDia(
                $checadas,
                $detalleHorario,
                $plantillaHorario,
                $fecha
            );
        }
        if (! $plantillaHorario || ! $detalleHorario) {
            $alertas[] = [
                'tipo'       => 'sin_horario',
                'nivel'      => 'warning',
                'parametros' => [],
                'mensaje'    => 'El colaborador no tiene un horario activo asignado para esta fecha.',
            ];
        }

        if ($plantillaHorario && $detalleHorario && ! $detalleHorario->labora) {
            if ($checadas->count() > 0) {
                $alertas[] = [
                    'tipo'       => 'dia_no_laborable_con_checadas',
                    'nivel'      => 'warning',
                    'parametros' => [],
                    'mensaje'    => 'El colaborador registró checadas en un día no laborable.',
                ];
            }
        }

        if ($jornada) {
            if ($jornada['estado_jornada'] === 'sin_checadas') {
                $alertas[] = [
                    'tipo'       => 'ausencia',
                    'nivel'      => 'danger',
                    'parametros' => [],
                    'mensaje'    => 'Día laborable sin checadas registradas.',
                ];
            }

            if ($jornada['estado_jornada'] === 'sin_entrada') {
                $alertas[] = [
                    'tipo'       => 'sin_entrada',
                    'nivel'      => 'danger',
                    'parametros' => [],
                    'mensaje'    => 'No existe entrada laboral registrada.',
                ];
            }

            if ($jornada['estado_jornada'] === 'sin_salida') {
                $alertas[] = [
                    'tipo'       => 'sin_salida',
                    'nivel'      => 'danger',
                    'parametros' => [],
                    'mensaje'    => 'No existe salida laboral registrada.',
                ];
            }

            $minutosRetardoDetectado =
            $jornada['incidencias']['retardo']['detectado_minutos'] ?? 0;

            $minutosRetardoFueraTolerancia =
            $jornada['incidencias']['retardo']['fuera_tolerancia_minutos'] ?? 0;

            if ($minutosRetardoDetectado > 0) {
                $fueraTolerancia = $minutosRetardoFueraTolerancia > 0;

                $alertas[] = [
                    'tipo'       => $fueraTolerancia
                        ? 'retardo_fuera_tolerancia'
                        : 'retardo_dentro_tolerancia',

                    'nivel'      => $fueraTolerancia ? 'warning' : 'info',

                    'parametros' => [
                        'minutos_detectados'       => $minutosRetardoDetectado,
                        'minutos_fuera_tolerancia' => $minutosRetardoFueraTolerancia,
                    ],

                    'mensaje'    => $fueraTolerancia
                        ? "Retardo detectado de {$minutosRetardoDetectado} minutos; {$minutosRetardoFueraTolerancia} fuera de tolerancia."
                        : "Retardo detectado de {$minutosRetardoDetectado} minutos dentro de tolerancia.",
                ];
            }

            $minutosSalidaAnticipadaDetectada =
            $jornada['incidencias']['salida_anticipada']['detectado_minutos'] ?? 0;

            $minutosSalidaAnticipadaFueraTolerancia =
            $jornada['incidencias']['salida_anticipada']['fuera_tolerancia_minutos'] ?? 0;

            if ($minutosSalidaAnticipadaDetectada > 0) {
                $fueraTolerancia = $minutosSalidaAnticipadaFueraTolerancia > 0;

                $alertas[] = [
                    'tipo'       => $fueraTolerancia
                        ? 'salida_anticipada_fuera_tolerancia'
                        : 'salida_anticipada_dentro_tolerancia',

                    'nivel'      => $fueraTolerancia ? 'warning' : 'info',

                    'parametros' => [
                        'minutos_detectados'       => $minutosSalidaAnticipadaDetectada,
                        'minutos_fuera_tolerancia' => $minutosSalidaAnticipadaFueraTolerancia,
                    ],

                    'mensaje'    => $fueraTolerancia
                        ? "Salida anticipada detectada de {$minutosSalidaAnticipadaDetectada} minutos; {$minutosSalidaAnticipadaFueraTolerancia} fuera de tolerancia."
                        : "Salida anticipada detectada de {$minutosSalidaAnticipadaDetectada} minutos dentro de tolerancia.",
                ];
            }

            $minutosExtraDetectados =
            $jornada['extra']['resumen']['minutos_detectados'] ?? 0;

            if ($minutosExtraDetectados > 0) {
                $alertas[] = [
                    'tipo'       => 'tiempo_extra_detectado',
                    'nivel'      => 'warning',
                    'parametros' => [
                        'minutos_detectados' => $minutosExtraDetectados,
                    ],
                    'mensaje'    => "Tiempo extra detectado de {$minutosExtraDetectados} minutos pendiente de aprobación.",
                ];
            }
        }

        $contextoAdministracion = [
            'jornada'  => $jornada,
            'checadas' => $checadas->map(function ($item) {
                return [
                    'id'                 => $item->id,
                    'fecha'              => $item->fecha?->format('Y-m-d'),
                    'check_time'         => $item->check_time?->format('Y-m-d H:i:s'),
                    'hora'               => $item->check_time?->format('H:i'),
                    'tipo'               => $item->tipo,
                    'clase'              => $item->clase,
                    'origen'             => $item->origen,
                    'metodo_validacion'  => $item->metodo_validacion,
                    'estatus_validacion' => $item->estatus_validacion,
                    'observacion'        => $item->observacion,
                    'tiene_evidencia'    => ! empty($item->evidencia_foto),
                ];
            })->values()->toArray(),
            'horario'  => [
                'tiene_horario' => (bool) ($asignacion && $plantillaHorario),
                'labora'        => (bool) ($detalleHorario?->labora ?? false),
            ],
        ];

        $accionesPermitidas = app(AttendanceAdministrationService::class)
            ->resolveActions($contextoAdministracion);
        return response()->json([
            'ok'   => true,
            'data' => [
                'fecha'             => $fecha,

                'horario'           => [
                    'tiene_horario'          => (bool) ($asignacion && $plantillaHorario),
                    'id_asignacion'          => $asignacion?->id,
                    'id_plantilla'           => $plantillaHorario?->id,
                    'nombre'                 => $plantillaHorario?->nombre,
                    'timezone'               => $plantillaHorario?->timezone,
                    'dia_semana'             => $detalleHorario?->dia_semana,
                    'labora'                 => (bool) ($detalleHorario?->labora ?? false),
                    'hora_entrada'           => $detalleHorario?->hora_entrada,
                    'hora_salida'            => $detalleHorario?->hora_salida,

                    'tolerancia_entrada_min' => $plantillaHorario?->tolerancia_entrada_min,
                    'tolerancia_salida_min'  => $plantillaHorario?->tolerancia_salida_min,
                    'permite_descanso'       => (bool) ($plantillaHorario?->permite_descanso ?? false),
                ],

                'ventana_operativa' => $ventanaOperativa,
                'jornada'           => $jornada,
                'checadas'          => $checadas->map(function ($item) {
                    return [
                        'id'                 => $item->id,
                        'fecha'              => $item->fecha?->format('Y-m-d'),
                        'check_time'         => $item->check_time?->format('Y-m-d H:i:s'),
                        'hora'               => $item->check_time?->format('H:i'),
                        'tipo'               => $item->tipo,
                        'clase'              => $item->clase,
                        'origen'             => $item->origen,
                        'metodo_validacion'  => $item->metodo_validacion,
                        'estatus_validacion' => $item->estatus_validacion,
                        'observacion'        => $item->observacion,
                        'tiene_evidencia'    => ! empty($item->evidencia_foto),
                    ];
                })->values(),
                'alertas'           => $alertas,
                'administracion'    => $accionesPermitidas,
            ],
        ]);
    }
    public function ejecutarAccionAdministrativa(
        Request $request,
        int $id,
        AttendanceAdministrationCommandService $commandService
    ) {
        $payload = $request->validate([
            'action' => [
                'required',
                'string',
                'in:registrar_entrada_jornada,registrar_par_jornada,registrar_par_intermedio,cerrar_movimiento_abierto,editar_checada',
            ],
            'fecha'  => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
            'data'   => ['required', 'array'],
        ]);

        $administrador = $request->user();

        $empleado = $this->findAuthorizedEmployee(
            $request,
            $id
        );

        if (! $empleado) {
            return response()->json([
                'success' => false,
                'code'    => 'EMPLOYEE_NOT_FOUND',
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        /*
     * Estos valores provienen del token y del empleado autorizado.
     * Ignoramos los IDs enviados por el frontend.
     */
        $payload['id_portal']   = (int) $administrador->id_portal;
        $payload['id_cliente']  = (int) $empleado->id_cliente;
        $payload['id_usuario']  = (int) $administrador->id;
        $payload['id_empleado'] = (int) $empleado->id;

        /*
     * La identidad de auditoría también se obtiene del token.
     */
        $payload['data']['admin_user_name'] =
        $administrador->nombre ?: 'Administrador';

        try {
            $commandService->execute($payload);

            /*
         * contextoDia vuelve a obtener portal y cliente
         * desde la autenticación, no desde el request.
         */
            $contextResponse = $this->contextoDia(
                $request,
                (int) $empleado->id
            );

            $contextData = $contextResponse->getData(true);

            if (($contextData['ok'] ?? false) !== true) {
                return $contextResponse;
            }

            return response()->json([
                'success' => true,
                'data'    => $contextData['data'],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'code'    => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Error ejecutando acción administrativa de checadas', [
                'id_portal'        => (int) $administrador->id_portal,
                'id_cliente'       => (int) $empleado->id_cliente,
                'id_empleado'      => (int) $empleado->id,
                'id_administrador' => (int) $administrador->id,
                'action'           => $payload['action'],
                'fecha'            => $payload['fecha'],
                'error'            => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'code'    => 'ADMIN_ATTENDANCE_ACTION_FAILED',
                'message' => 'No fue posible completar la acción administrativa.',
            ], 500);
        }
    }
    private function findAuthorizedEmployee(
        Request $request,
        int $idEmpleado
    ): ?object {
        $administrador = $request->user();

        return DB::connection('portal_main')
            ->table('empleados')
            ->where('id', $idEmpleado)
            ->where('id_portal', (int) $administrador->id_portal)
            ->where(function ($query) {
                $query->where('eliminado', 0)
                    ->orWhereNull('eliminado');
            })
            ->first();
    }
}
