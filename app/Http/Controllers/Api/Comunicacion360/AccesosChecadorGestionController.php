<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Services\Checador\AttendanceAdministrationCommandService;
use App\Services\Checador\AttendanceAdministrationService;
use App\Services\Checador\AttendanceDayContextService;
use App\Services\Checador\JornadaCalculoService;
use Illuminate\Http\Request;
use Throwable;

class AccesosChecadorGestionController extends Controller
{
    public function contextoDia(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');
        $fecha    = $request->input('fecha', now()->toDateString());

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $contexto = app(AttendanceDayContextService::class)->resolver(
            (int) $idPortal,
            (int) $id,
            $fecha
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
                'tipo'    => 'sin_horario',
                'nivel'   => 'warning',
                'mensaje' => 'El colaborador no tiene un horario activo asignado para esta fecha.',
            ];
        }

        if ($plantillaHorario && $detalleHorario && ! $detalleHorario->labora) {
            if ($checadas->count() > 0) {
                $alertas[] = [
                    'tipo'    => 'dia_no_laborable_con_checadas',
                    'nivel'   => 'warning',
                    'mensaje' => 'El colaborador registró checadas en un día no laborable.',
                ];
            }
        }

        if ($jornada) {
            if ($jornada['estado_jornada'] === 'sin_checadas') {
                $alertas[] = [
                    'tipo'    => 'ausencia',
                    'nivel'   => 'danger',
                    'mensaje' => 'Día laborable sin checadas registradas.',
                ];
            }

            if ($jornada['estado_jornada'] === 'sin_entrada') {
                $alertas[] = [
                    'tipo'    => 'sin_entrada',
                    'nivel'   => 'danger',
                    'mensaje' => 'No existe entrada laboral registrada.',
                ];
            }

            if ($jornada['estado_jornada'] === 'sin_salida') {
                $alertas[] = [
                    'tipo'    => 'sin_salida',
                    'nivel'   => 'danger',
                    'mensaje' => 'No existe salida laboral registrada.',
                ];
            }

            $minutosRetardoDetectado =
            $jornada['incidencias']['retardo']['detectado_minutos'] ?? 0;

            $minutosRetardoFueraTolerancia =
            $jornada['incidencias']['retardo']['fuera_tolerancia_minutos'] ?? 0;

            if ($minutosRetardoDetectado > 0) {
                $alertas[] = [
                    'tipo'    => 'retardo',
                    'nivel'   => $minutosRetardoFueraTolerancia > 0 ? 'warning' : 'info',
                    'mensaje' => $minutosRetardoFueraTolerancia > 0
                        ? "Retardo detectado de {$minutosRetardoDetectado} minutos; {$minutosRetardoFueraTolerancia} fuera de tolerancia."
                        : "Retardo detectado de {$minutosRetardoDetectado} minutos dentro de tolerancia.",
                ];
            }

            $minutosSalidaAnticipadaDetectada =
            $jornada['incidencias']['salida_anticipada']['detectado_minutos'] ?? 0;

            $minutosSalidaAnticipadaFueraTolerancia =
            $jornada['incidencias']['salida_anticipada']['fuera_tolerancia_minutos'] ?? 0;

            if ($minutosSalidaAnticipadaDetectada > 0) {
                $alertas[] = [
                    'tipo'    => 'salida_anticipada',
                    'nivel'   => $minutosSalidaAnticipadaFueraTolerancia > 0 ? 'warning' : 'info',
                    'mensaje' => $minutosSalidaAnticipadaFueraTolerancia > 0
                        ? "Salida anticipada detectada de {$minutosSalidaAnticipadaDetectada} minutos; {$minutosSalidaAnticipadaFueraTolerancia} fuera de tolerancia."
                        : "Salida anticipada detectada de {$minutosSalidaAnticipadaDetectada} minutos dentro de tolerancia.",
                ];
            }

            $minutosExtraDetectados =
            $jornada['extra']['resumen']['minutos_detectados'] ?? 0;

            if ($minutosExtraDetectados > 0) {
                $alertas[] = [
                    'tipo'    => 'tiempo_extra_detectado',
                    'nivel'   => 'warning',
                    'mensaje' => "Tiempo extra detectado de {$minutosExtraDetectados} minutos pendiente de aprobación.",
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
        try {
            $payload = $request->validate([
                'id_portal'  => ['required', 'integer'],
                'id_cliente' => ['required', 'integer'],
                'id_usuario' => ['required', 'integer'],
                'action'     => ['required', 'string'],
                'fecha'      => ['required', 'date_format:Y-m-d'],
                'motivo'     => ['required', 'string', 'min:3'],
                'data'       => ['required', 'array'],
            ]);

            $payload['id_empleado'] = $id;

            $commandService->execute($payload);

            /*
         * Después de ejecutar el comando, volvemos a construir el contexto
         * usando el mismo método empleado por el GET.
         */
            $contextResponse = $this->contextoDia($request, $id);

            $contextData = $contextResponse->getData(true);

            if (($contextData['ok'] ?? false) !== true) {
                return $contextResponse;
            }

            return response()->json([
                'success' => true,
                'data'    => $contextData['data'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
