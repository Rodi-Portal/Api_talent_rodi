<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\Checada;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChecadorRegularizacionService
{
    public function preview(array $data): array
    {
        $pendiente = $this->obtenerJornadaPendiente($data);

        if (! $pendiente['ok']) {
            return $pendiente;
        }

        $preview = $this->calcularRegularizacion(
            $pendiente['entrada'],
            $data
        );

        if (! $preview['ok']) {
            return $preview;
        }

        return [
            'ok'                 => true,
            'code'               => 'pending_checkout_can_be_resolved',
            'message'            => 'pending_checkout_can_be_resolved',
            'entrada'            => [
                'id'         => $pendiente['entrada']->id,
                'fecha'      => $pendiente['entrada']->fecha,
                'check_time' => $pendiente['entrada']->check_time,
                'tipo'       => $pendiente['entrada']->tipo,
                'clase'      => $pendiente['entrada']->clase,
            ],
            'resolution_preview' => $preview['resolution_preview'],
        ];
    }

    private function obtenerJornadaPendiente(array $data): array
    {
        $checkinId  = (int) ($data['checkin_id'] ?? 0);
        $idPortal   = (int) ($data['id_portal'] ?? 0);
        $idCliente  = (int) ($data['id_cliente'] ?? 0);
        $idEmpleado = (int) ($data['id_empleado'] ?? 0);

        if ($checkinId <= 0 || $idPortal <= 0 || $idEmpleado <= 0) {
            return [
                'ok'      => false,
                'code'    => 'invalid_request',
                'message' => 'invalid_request',
            ];
        }

        $entrada = DB::connection('portal_main')
            ->table('checadas')
            ->where('id', $checkinId)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('tipo', 'in')
            ->where('clase', 'work')
            ->first();

        if (! $entrada) {
            return [
                'ok'      => false,
                'code'    => 'pending_checkin_not_found',
                'message' => 'pending_checkin_not_found',
            ];
        }

        $salidaExistente = DB::connection('portal_main')
            ->table('checadas')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('tipo', 'out')
            ->where('clase', 'work')
            ->where('fecha', $entrada->fecha)
            ->where('check_time', '>', $entrada->check_time)
            ->exists();

        if ($salidaExistente) {
            return [
                'ok'      => true,
                'code'    => 'pending_checkout_already_resolved',
                'message' => 'pending_checkout_already_resolved',
                'entrada' => $entrada,
            ];
        }

        return [
            'ok'      => true,
            'entrada' => $entrada,
        ];
    }

    private function calcularRegularizacion(object $entrada, array $data): array
    {
        $idPortal   = (int) ($data['id_portal'] ?? 0);
        $idCliente  = (int) ($data['id_cliente'] ?? 0);
        $idEmpleado = (int) ($data['id_empleado'] ?? 0);

        $fechaEntrada = Carbon::parse($entrada->fecha)->toDateString();

        $asignacion = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $fechaEntrada)
            ->where(function ($query) use ($fechaEntrada) {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fechaEntrada);
            })
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        if (! $asignacion) {
            return [
                'ok'      => false,
                'code'    => 'assignment_not_found',
                'message' => 'assignment_not_found',
            ];
        }

        $horario = DB::connection('portal_main')
            ->table('checador_horario_plantillas')
            ->where('id', (int) $asignacion->id_plantilla_horario)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('activo', 1)
            ->first();

        if (! $horario || empty($horario->timezone)) {
            return [
                'ok'      => false,
                'code'    => 'schedule_timezone_not_found',
                'message' => 'schedule_timezone_not_found',
            ];
        }

        $fechaOperativa = Carbon::parse($fechaEntrada, $horario->timezone);

        $detalle = DB::connection('portal_main')
            ->table('checador_horario_detalles')
            ->where('id_plantilla', (int) $horario->id)
            ->where('dia_semana', (int) $fechaOperativa->dayOfWeek)
            ->where('labora', 1)
            ->orderBy('orden')
            ->first();

        if (! $detalle || empty($detalle->hora_salida)) {
            return [
                'ok'      => false,
                'code'    => 'checkout_time_not_configured',
                'message' => 'checkout_time_not_configured',
            ];
        }

        $effectiveCheckoutAt = Carbon::parse(
            $fechaEntrada . ' ' . $detalle->hora_salida,
            $horario->timezone
        );

        $checkinAt = Carbon::parse(
            $entrada->check_time,
            $horario->timezone
        );

        if ($effectiveCheckoutAt->lessThanOrEqualTo($checkinAt)) {
            $effectiveCheckoutAt = $checkinAt->copy()->addSecond();
        }

        $confirmedAt = ! empty($data['confirmed_at'])
            ? Carbon::parse($data['confirmed_at'])->setTimezone($horario->timezone)
            : Carbon::now($horario->timezone);

        return [
            'ok'                 => true,
            'resolution_preview' => [
                'effective_checkout_at' => $effectiveCheckoutAt->format('Y-m-d H:i:s'),
                'confirmed_at'          => $confirmedAt->format('Y-m-d H:i:s'),
                'timezone'              => $horario->timezone,
                'overtime_authorized'   => false,
                'reason'                => 'scheduled_checkout_time',
            ],
        ];
    }
    private function obtenerJornadaPendienteConLock(array $data): array
    {
        $checkinId  = (int) ($data['checkin_id'] ?? 0);
        $idPortal   = (int) ($data['id_portal'] ?? 0);
        $idCliente  = (int) ($data['id_cliente'] ?? 0);
        $idEmpleado = (int) ($data['id_empleado'] ?? 0);

        if ($checkinId <= 0 || $idPortal <= 0 || $idEmpleado <= 0) {
            return [
                'ok'      => false,
                'code'    => 'invalid_request',
                'message' => 'invalid_request',
            ];
        }

        $entrada = DB::connection('portal_main')
            ->table('checadas')
            ->where('id', $checkinId)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('tipo', 'in')
            ->where('clase', 'work')
            ->lockForUpdate()
            ->first();

        if (! $entrada) {
            return [
                'ok'      => false,
                'code'    => 'pending_checkin_not_found',
                'message' => 'pending_checkin_not_found',
            ];
        }

        $salidaExistente = DB::connection('portal_main')
            ->table('checadas')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('tipo', 'out')
            ->where('clase', 'work')
            ->where('fecha', $entrada->fecha)
            ->where('check_time', '>', $entrada->check_time)
            ->lockForUpdate()
            ->exists();

        if ($salidaExistente) {
            return [
                'ok'      => false,
                'code'    => 'pending_checkout_already_resolved',
                'message' => 'pending_checkout_already_resolved',
            ];
        }

        return [
            'ok'      => true,
            'entrada' => $entrada,
        ];
    }

    private function crearSalidaRegularizada(
        object $entrada,
        array $regularizacion,
        array $data
    ): object {
        $preview = $regularizacion['resolution_preview'];

        $metadata = [
            'regularization' => [
                'version'    => 1,
                'type'       => 'pending_work_checkout',

                'source'     => [
                    'performed_by' => $data['performed_by'] ?? [
                        'type' => 'employee',
                        'id'   => (int) ($data['id_empleado'] ?? 0),
                    ],
                    'channel'      => 'miportal',
                ],

                'original'   => [
                    'checkin_id'    => $entrada->id,
                    'checkin_at'    => $entrada->check_time,
                    'checkin_fecha' => $entrada->fecha,
                    'checkin_tipo'  => $entrada->tipo,
                    'checkin_clase' => $entrada->clase,
                ],

                'resolution' => [
                    'effective_checkout_at' => $preview['effective_checkout_at'],
                    'confirmed_at'          => $preview['confirmed_at'],
                    'timezone'              => $preview['timezone'],
                    'reason'                => $preview['reason'],
                    'overtime_authorized'   => $preview['overtime_authorized'],
                ],

                'audit'      => [
                    'ip_address'       => $data['ip_address'] ?? null,
                    'device_info'      => $data['device_info'] ?? null,
                    'device_timezone'  => $data['timezone'] ?? null,
                    'latitud'          => $data['latitud'] ?? null,
                    'longitud'         => $data['longitud'] ?? null,
                    'precision_metros' => $data['precision_metros'] ?? null,
                ],
            ],
        ];

        $hash = sha1(
            $entrada->id_portal . '|'
            . $entrada->id_cliente . '|'
            . $entrada->id_empleado . '|'
            . $preview['effective_checkout_at'] . '|'
            . 'regularizacion_colaborador'
        );

        return Checada::create([
            'id_portal'          => (int) $entrada->id_portal,
            'id_cliente'         => (int) $entrada->id_cliente,
            'id_empleado'        => (int) $entrada->id_empleado,
            'id_asignacion'      => $entrada->id_asignacion ?? null,
            'id_ubicacion'       => null,

            'fecha'              => Carbon::parse($preview['effective_checkout_at'])->toDateString(),
            'check_time'         => $preview['effective_checkout_at'],

            'tipo'               => 'out',
            'clase'              => 'work',

            'dispositivo'        => 'web',
            'origen'             => 'api',
            'metodo_validacion'  => 'regularizacion_colaborador',
            'estatus_validacion' => 'advertida',

            'distancia_metros'   => null,
            'precision_metros'   => $data['precision_metros'] ?? null,
            'latitud'            => $data['latitud'] ?? null,
            'longitud'           => $data['longitud'] ?? null,

            'evidencia_foto'     => null,
            'qr_token'           => null,
            'ip_address'         => $data['ip_address'] ?? null,
            'timezone'           => $preview['timezone'],
            'device_info'        => $data['device_info'] ?? null,

            'metadata'           => $metadata,
            'observacion'        => 'regularized_checkout_by_employee',
            'hash'               => $hash,
        ]);
    }

    private function registrarAuditoria(
        object $entrada,
        object $salida,
        array $regularizacion,
        array $data
    ): void {
        // Pendiente: registrar auditoría estructurada en metadata o tabla dedicada.
    }
    public function confirmar(array $data): array
    {
        return DB::connection('portal_main')->transaction(function () use ($data) {
            $pendiente = $this->obtenerJornadaPendienteConLock($data);

            if (! $pendiente['ok']) {
                return $pendiente;
            }

            $regularizacion = $this->calcularRegularizacion(
                $pendiente['entrada'],
                $data
            );

            if (! $regularizacion['ok']) {
                return $regularizacion;
            }

            $salida = $this->crearSalidaRegularizada(
                $pendiente['entrada'],
                $regularizacion,
                $data
            );

            $this->registrarAuditoria(
                $pendiente['entrada'],
                $salida,
                $regularizacion,
                $data
            );

            return [
                'ok'                 => true,
                'code'               => 'regularization_ready_to_execute',
                'message'            => 'regularization_ready_to_execute',
                'entrada'            => [
                    'id'         => $pendiente['entrada']->id,
                    'fecha'      => $pendiente['entrada']->fecha,
                    'check_time' => $pendiente['entrada']->check_time,
                    'tipo'       => $pendiente['entrada']->tipo,
                    'clase'      => $pendiente['entrada']->clase,
                ],
                'resolution_preview' => $regularizacion['resolution_preview'],
            ];
        });
    }
}
