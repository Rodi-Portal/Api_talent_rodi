<?php
namespace App\Services\Checador;

use App\Services\Checador\ChecadaRegistroService;
use App\Services\Checador\ChecadaValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecadaDispositivoService
{
    private string $connection = 'portal_main';

    public function registrar(Request $request): array
    {
        $data   = $request->all();
        $data   = $this->normalizarPayload($data);
        $device = $this->autenticarDispositivo($request, $data);

        if (! $device['ok']) {
            return $device;
        }
        $empleado = $this->resolverEmpleado($data);

        if (! $empleado['ok']) {
            return $empleado;
        }

        $data['id_empleado'] = $empleado['data']->id;
        $validator           = app(ChecadaValidationService::class);

        $resultado = $validator->validar($data);

        if (! $resultado['ok']) {
            return array_merge($resultado, [
                'status' => 422,
            ]);
        }

        $metadata = $this->construirMetadata($data, $device['data']);

        $data['metodo_validacion'] = implode(',', $resultado['metodos_requeridos'] ?? []);

        $id = app(ChecadaRegistroService::class)
            ->insertar($data, $resultado, $metadata);

        DB::connection($this->connection)
            ->table('checador_dispositivos')
            ->where('id', $device['data']->id)
            ->update([
                'ultimo_acceso' => now(),
            ]);

        return [
            'ok'       => true,
            'status'   => 200,
            'id'       => $id,
            'mensaje'  => 'Checada de dispositivo registrada correctamente.',
            'estatus'  => $resultado['estatus_validacion'],
            'motivo'   => $resultado['motivo'] ?? null,
            'empleado' => [
                'id'          => $empleado['data']->id,
                'id_empleado' => $empleado['data']->id_empleado,
            ],
        ];

    }

    private function autenticarDispositivo(Request $request, array $data): array
    {
        $deviceKey = $request->header('X-Device-Key');

        if (empty($deviceKey)) {
            return [
                'ok'      => false,
                'status'  => 401,
                'mensaje' => 'API key de dispositivo requerida.',
            ];
        }

        $device = DB::connection($this->connection)
            ->table('checador_dispositivos')
            ->where('api_key_hash', hash('sha256', $deviceKey))
            ->where('activo', 1)
            ->first();

        if (! $device) {
            return [
                'ok'      => false,
                'status'  => 401,
                'mensaje' => 'Dispositivo no autorizado.',
            ];
        }

        if ((int) $device->id_portal !== (int) ($data['id_portal'] ?? 0)) {
            return [
                'ok'      => false,
                'status'  => 403,
                'mensaje' => 'El dispositivo no pertenece al portal enviado.',
            ];
        }

        if (
            ! is_null($device->id_cliente)
            && (int) $device->id_cliente !== (int) ($data['id_cliente'] ?? 0)
        ) {
            return [
                'ok'      => false,
                'status'  => 403,
                'mensaje' => 'El dispositivo no pertenece al cliente enviado.',
            ];
        }

        if (
            ! empty($device->ip_permitida)
            && $device->ip_permitida !== $request->ip()
        ) {
            return [
                'ok'      => false,
                'status'  => 403,
                'mensaje' => 'IP no autorizada para este dispositivo.',
            ];
        }

        return [
            'ok'   => true,
            'data' => $device,
        ];
    }

    private function resolverEmpleado(array $data): array
    {
        $empleadoClave = $data['empleado_clave'] ?? $data['employee_code'] ?? $data['id_empleado_externo'] ?? $data['id_empleado'] ?? null;

        if (empty($empleadoClave)) {
            return [
                'ok'      => false,
                'status'  => 422,
                'mensaje' => 'Identificador de empleado requerido.',
            ];
        }

        $empleado = DB::connection($this->connection)
            ->table('empleados')
            ->where('id_portal', $data['id_portal'])
            ->where('id_cliente', $data['id_cliente'])
            ->where('id_empleado', $empleadoClave)
            ->first();

        if (! $empleado) {
            return [
                'ok'      => false,
                'status'  => 422,
                'mensaje' => 'Empleado no encontrado para el identificador enviado por el dispositivo.',
            ];
        }

        return [
            'ok'   => true,
            'data' => $empleado,
        ];
    }
    private function normalizarPayload(array $data): array
    {
        if (isset($data['tipo'])) {
            $data['tipo'] = strtolower(trim((string) $data['tipo']));
        }

        if (isset($data['clase'])) {
            $data['clase'] = strtolower(trim((string) $data['clase']));
        }

        if (isset($data['origen'])) {
            $data['origen'] = strtolower(trim((string) $data['origen']));
        }

        if (isset($data['empleado_clave'])) {
            $data['empleado_clave'] = trim((string) $data['empleado_clave']);
        }

        if (isset($data['check_time'])) {
            $data['check_time'] = trim((string) $data['check_time']);
        }

        return $data;
    }
    private function construirMetadata(array $data, object $device): array
    {
        $metadata = $data['metadata'] ?? [];

        $metadata['dispositivo_autenticado'] = [
            'id'     => $device->id,
            'clave'  => $device->clave,
            'tipo'   => $device->tipo,
            'marca'  => $device->marca,
            'modelo' => $device->modelo,
        ];

        if (($data['origen'] ?? null) === 'biometrico') {
            $metadata['biometrico'] = array_merge([
                'tipo'           => $data['biometrico_tipo'] ?? null,
                'marca'          => $data['device_brand'] ?? $device->marca,
                'modelo'         => $data['device_model'] ?? $device->modelo,
                'device_id'      => $data['device_id'] ?? $device->clave,
                'confidence'     => $data['confidence'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
            ], $metadata['biometrico'] ?? []);
        }

        return $metadata;
    }
}
