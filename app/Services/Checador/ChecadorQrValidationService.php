<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ChecadorQrValidationService
{
    public function validar(
        string $token,
        bool $lockForUpdate = false
    ): array {
        try {
            $decrypted = Crypt::decryptString($token);
            $payload   = json_decode($decrypted, true);
        } catch (\Throwable $e) {
            return $this->error(
                'invalid_qr',
                'QR inválido.',
                422
            );
        }

        if (! is_array($payload)) {
            return $this->error(
                'invalid_qr_content',
                'El contenido del QR no es válido.',
                422
            );
        }

        $camposRequeridos = [
            'type',
            'modo',
            'ubicacion_id',
            'id_portal',
            'id_cliente',
            'generated_at',
            'expires_at',
        ];

        foreach ($camposRequeridos as $campo) {
            if (! array_key_exists($campo, $payload)) {
                return $this->error(
                    'incomplete_qr_content',
                    'El contenido del QR está incompleto.',
                    422
                );
            }
        }

        if ($payload['type'] !== 'checador_qr') {
            return $this->error(
                'invalid_qr_type',
                'El QR no pertenece al checador.',
                422
            );
        }

        if (
            ! is_numeric($payload['ubicacion_id']) ||
            ! is_numeric($payload['id_portal']) ||
            ! is_numeric($payload['id_cliente']) ||
            ! is_numeric($payload['generated_at'])
        ) {
            return $this->error(
                'invalid_qr_context',
                'El contexto del QR no es válido.',
                422
            );
        }

        $modo = $payload['modo'];

        if (! in_array($modo, ['fijo', 'dinamico'], true)) {
            return $this->error(
                'invalid_qr_mode',
                'El modo del QR no es válido.',
                422
            );
        }

        if ($modo === 'dinamico') {
            if (
                empty($payload['expires_at']) ||
                ! is_numeric($payload['expires_at'])
            ) {
                return $this->error(
                    'invalid_qr_expiration',
                    'La vigencia del QR no es válida.',
                    422
                );
            }

            if ((int) $payload['expires_at'] <= now()->timestamp) {
                return $this->error(
                    'expired_qr',
                    'El QR expiró.',
                    410
                );
            }
        }

        $ubicacionQuery = ChecadorUbicacion::query()
            ->where('id', (int) $payload['ubicacion_id'])
            ->where('id_portal', (int) $payload['id_portal'])
            ->where('id_cliente', (int) $payload['id_cliente'])
            ->where('activa', 1);

        if ($lockForUpdate) {
            $ubicacionQuery->lockForUpdate();
        }

        $ubicacion = $ubicacionQuery->first();

        if (! $ubicacion) {
            return $this->error(
                'qr_location_not_found',
                'La ubicación del QR no existe o está inactiva.',
                404
            );
        }

        $modosPermitidos = [
            'ninguno'  => [],
            'fijo'     => ['fijo'],
            'dinamico' => ['dinamico'],
            'ambos'    => ['fijo', 'dinamico'],
        ];

        $permitidos = $modosPermitidos[$ubicacion->qr_modo] ?? [];

        if (! in_array($modo, $permitidos, true)) {
            return $this->error(
                'qr_mode_disabled',
                'Este modo QR ya no está habilitado para la ubicación.',
                422
            );
        }

        if ($modo === 'fijo') {
            $tokenHash = hash('sha256', $token);

            if (
                empty($ubicacion->qr_token_fijo_hash) ||
                ! hash_equals(
                    (string) $ubicacion->qr_token_fijo_hash,
                    $tokenHash
                )
            ) {
                return $this->error(
                    'fixed_qr_revoked',
                    'El QR fijo fue revocado o ya no es válido.',
                    422
                );
            }
        }

        return [
            'ok'         => true,
            'code'       => 'valid_qr',
            'message'    => 'QR válido.',
            'status'     => 200,
            'token_hash' => hash('sha256', $token),
            'payload'    => [
                'type'         => $payload['type'],
                'modo'         => $modo,
                'ubicacion_id' => (int) $payload['ubicacion_id'],
                'id_portal'    => (int) $payload['id_portal'],
                'id_cliente'   => (int) $payload['id_cliente'],
                'generated_at' => (int) $payload['generated_at'],
                'expires_at'   => $payload['expires_at'] !== null
                    ? (int) $payload['expires_at']
                    : null,
            ],
            'ubicacion'  => $ubicacion,
        ];
    }
    public function validarParaRegistroEmpleado(
        string $token,
        int $idPortal,
        int $idCliente,
        int $idPlantillaChecada,
        $latitud,
        $longitud,
        $precisionMetros,
        bool $lockForUpdate = false
    ): array {
        $resultado = $this->validar(
            $token,
            $lockForUpdate
        );
        if (! $resultado['ok']) {
            return $resultado;
        }

        $payload   = $resultado['payload'];
        $ubicacion = $resultado['ubicacion'];

        /*
    |--------------------------------------------------------------------------
    | CONTEXTO DEL COLABORADOR
    |--------------------------------------------------------------------------
    */

        if (
            (int) $payload['id_portal'] !== $idPortal ||
            (int) $payload['id_cliente'] !== $idCliente
        ) {
            return $this->error(
                'qr_employee_context_mismatch',
                'El QR no pertenece al portal o cliente del colaborador.',
                422
            );
        }

        /*
    |--------------------------------------------------------------------------
    | UBICACIÓN ASIGNADA A LA PLANTILLA
    |--------------------------------------------------------------------------
    */

        $ubicacionPermitida = DB::connection('portal_main')
            ->table('checador_checada_plantilla_ubicaciones')
            ->where('id_plantilla', $idPlantillaChecada)
            ->where('id_ubicacion', (int) $ubicacion->id)
            ->where('activo', 1)
            ->exists();

        if (! $ubicacionPermitida) {
            return $this->error(
                'qr_location_not_allowed_for_template',
                'La ubicación del QR no está permitida para la plantilla del colaborador.',
                422
            );
        }

        /*
    |--------------------------------------------------------------------------
    | GPS OBLIGATORIO PARA QR
    |--------------------------------------------------------------------------
    */

        if (
            $latitud === null ||
            $latitud === '' ||
            $longitud === null ||
            $longitud === '' ||
            ! is_numeric($latitud) ||
            ! is_numeric($longitud)
        ) {
            return $this->error(
                'qr_gps_required',
                'La ubicación GPS es obligatoria para registrar mediante QR.',
                422
            );
        }

        $latitudEmpleado  = (float) $latitud;
        $longitudEmpleado = (float) $longitud;

        if (
            $latitudEmpleado < -90 ||
            $latitudEmpleado > 90 ||
            $longitudEmpleado < -180 ||
            $longitudEmpleado > 180
        ) {
            return $this->error(
                'qr_invalid_gps_coordinates',
                'Las coordenadas GPS no son válidas.',
                422
            );
        }

        /*
    |--------------------------------------------------------------------------
    | PRECISIÓN GPS
    |--------------------------------------------------------------------------
    */

        if (
            $precisionMetros === null ||
            $precisionMetros === '' ||
            ! is_numeric($precisionMetros)
        ) {
            return $this->error(
                'qr_gps_accuracy_required',
                'No fue posible determinar la precisión del GPS.',
                422
            );
        }

        $precisionMetros = (float) $precisionMetros;

        if ($precisionMetros < 0 || $precisionMetros > 200) {
            return $this->error(
                'qr_gps_accuracy_insufficient',
                'La precisión del GPS no es suficiente para validar el QR.',
                422
            );
        }

        /*
    |--------------------------------------------------------------------------
    | GEOZONA DE LA UBICACIÓN DEL QR
    |--------------------------------------------------------------------------
    */

        if ($ubicacion->tipo_zona !== 'circle') {
            return $this->error(
                'qr_location_zone_not_supported',
                'El tipo de geozona de la ubicación QR todavía no es compatible.',
                422
            );
        }

        if (
            $ubicacion->latitud === null ||
            $ubicacion->longitud === null ||
            $ubicacion->radio_metros === null
        ) {
            return $this->error(
                'qr_location_incomplete',
                'La ubicación del QR no tiene una geocerca completa.',
                422
            );
        }

        $distanciaMetros = $this->calcularDistanciaMetros(
            $latitudEmpleado,
            $longitudEmpleado,
            (float) $ubicacion->latitud,
            (float) $ubicacion->longitud
        );

        if ($distanciaMetros > (float) $ubicacion->radio_metros) {
            return [
                'ok'               => false,
                'code'             => 'outside_qr_location',
                'message'          => 'El colaborador está fuera de la ubicación correspondiente al QR.',
                'status'           => 422,
                'id_ubicacion'     => (int) $ubicacion->id,
                'distancia_metros' => round($distanciaMetros, 2),
                'radio_metros'     => (float) $ubicacion->radio_metros,
                'precision_metros' => $precisionMetros,
            ];
        }

        return [
            'ok'                 => true,
            'code'               => 'valid_qr_for_employee',
            'message'            => 'QR y ubicación GPS validados correctamente.',
            'status'             => 200,
            'estatus_validacion' => 'valida',

            'id_ubicacion'       => (int) $ubicacion->id,
            'distancia_metros'   => round($distanciaMetros, 2),
            'precision_metros'   => $precisionMetros,
            'latitud'            => $latitudEmpleado,
            'longitud'           => $longitudEmpleado,

            'token_hash'         => $resultado['token_hash'],
            'payload'            => $payload,
            'ubicacion'          => $ubicacion,
        ];
    }
    private function calcularDistanciaMetros(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $radioTierra = 6371000;

        $lat1Rad  = deg2rad($lat1);
        $lat2Rad  = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a =
        sin($deltaLat / 2) * sin($deltaLat / 2) +
        cos($lat1Rad) *
        cos($lat2Rad) *
        sin($deltaLng / 2) *
        sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $radioTierra * $c;
    }
    private function error(
        string $code,
        string $message,
        int $status
    ): array {
        return [
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
            'status'  => $status,
        ];
    }
}
