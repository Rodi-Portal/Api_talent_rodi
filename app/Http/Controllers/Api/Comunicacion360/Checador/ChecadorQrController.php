<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ChecadorQrController extends Controller
{
    public function generar(Request $request)
    {
        $data = $request->validate([
            'ubicacion_id' => ['required', 'integer'],
            'modo'         => ['nullable', 'in:fijo,dinamico'],
        ]);

        $ubicacion = ChecadorUbicacion::where('id', $data['ubicacion_id'])
            ->where('activa', 1)
            ->first();
        $modo = $data['modo'] ?? 'dinamico';
        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación no existe o está inactiva.',
            ], 404);
        }
        $modosPermitidos = [
            'ninguno'  => [],
            'fijo'     => ['fijo'],
            'dinamico' => ['dinamico'],
            'ambos'    => ['fijo', 'dinamico'],
        ];

        $permitidos = $modosPermitidos[$ubicacion->qr_modo] ?? [];

        if (! in_array($modo, $permitidos, true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'El modo QR solicitado no está habilitado para esta ubicación.',
            ], 422);
        }
        $payload = [
            'type'         => 'checador_qr',
            'modo'         => $modo,
            'ubicacion_id' => $ubicacion->id,
            'id_portal'    => $ubicacion->id_portal,
            'id_cliente'   => $ubicacion->id_cliente,
            'generated_at' => now()->timestamp,

            'expires_at'   => $modo === 'dinamico'
                ? now()
                ->addSeconds($ubicacion->qr_expira_segundos ?: 60)
                ->timestamp
                : null,
        ];

        $token = Crypt::encryptString(json_encode($payload));
        if ($modo === 'fijo') {
            $ubicacion->update([
                'qr_token_fijo_hash'      => hash('sha256', $token),
                'qr_token_fijo_encrypted' => $token,
                'qr_actualizado_en'       => now(),
            ]);
        }
        return response()->json([
            'ok'                 => true,
            'token'              => $token,
            'expires_in_seconds' => $modo === 'dinamico'
                ? ($ubicacion->qr_expira_segundos ?: 60)
                : null,

            'modo'               => $modo, 'ubicacion' => [
                'id'     => $ubicacion->id,
                'nombre' => $ubicacion->nombre,
            ],
        ]);
    }

    public function mostrarFijo(Request $request, $ubicacionId)
    {
        $contexto = $request->validate([
            'id_portal'  => ['required', 'integer'],
            'id_cliente' => ['required', 'integer'],
        ]);

        $ubicacion = ChecadorUbicacion::where('id', $ubicacionId)
            ->where('id_portal', $contexto['id_portal'])
            ->where('id_cliente', $contexto['id_cliente'])
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación no existe en el contexto solicitado.',
            ], 404);
        }

        if (! in_array($ubicacion->qr_modo, ['fijo', 'ambos'], true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación no tiene habilitado un QR fijo.',
            ], 422);
        }

        if (empty($ubicacion->qr_token_fijo_encrypted)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación todavía no tiene un QR fijo recuperable. Debe regenerarlo una vez.',
            ], 404);
        }

        try {
            /*
         * El cast "encrypted" del modelo devuelve aquí
         * el token original ya descifrado.
         */
            $token = $ubicacion->qr_token_fijo_encrypted;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok'      => false,
                'message' => 'No fue posible recuperar el QR fijo.',
            ], 500);
        }

        /*
     * Verificamos que el valor recuperado siga correspondiendo
     * al hash autorizado actualmente.
     */
        $tokenHash = hash('sha256', $token);

        if (
            empty($ubicacion->qr_token_fijo_hash) ||
            ! hash_equals($ubicacion->qr_token_fijo_hash, $tokenHash)
        ) {
            return response()->json([
                'ok'      => false,
                'message' => 'El QR fijo almacenado no coincide con el QR autorizado.',
            ], 409);
        }

        return response()->json([
            'ok'             => true,
            'token'          => $token,
            'ubicacion'      => [
                'id'     => $ubicacion->id,
                'nombre' => $ubicacion->nombre,
                'activa' => (bool) $ubicacion->activa,
            ],
            'actualizado_en' => optional(
                $ubicacion->qr_actualizado_en
            )->toIso8601String(),
        ]);
    }

    public function validar(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            $payload = json_decode(Crypt::decryptString($data['token']), true);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'QR inválido.',
            ], 422);
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

        if (! is_array($payload)) {
            return response()->json([
                'ok'      => false,
                'message' => 'El contenido del QR no es válido.',
            ], 422);
        }

        foreach ($camposRequeridos as $campo) {
            if (! array_key_exists($campo, $payload)) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'El contenido del QR está incompleto.',
                ], 422);
            }
        }

        if (
            ! is_numeric($payload['ubicacion_id']) ||
            ! is_numeric($payload['id_portal']) ||
            ! is_numeric($payload['id_cliente'])
        ) {
            return response()->json([
                'ok'      => false,
                'message' => 'El contexto del QR no es válido.',
            ], 422);
        }

        if (($payload['type'] ?? null) !== 'checador_qr') {
            return response()->json([
                'ok'      => false,
                'message' => 'El QR no pertenece al checador.',
            ], 422);
        }

        $modo = $payload['modo'] ?? null;

        if (! in_array($modo, ['fijo', 'dinamico'], true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'El modo del QR no es válido.',
            ], 422);
        }

        if ($modo === 'dinamico') {
            if (empty($payload['expires_at'])) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'El QR dinámico no tiene vigencia válida.',
                ], 422);
            }
            if (! is_numeric($payload['expires_at'])) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'La vigencia del QR no es válida.',
                ], 422);
            }

            $expiresAt = (int) $payload['expires_at'];

            if ($expiresAt <= now()->timestamp) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'El QR expiró.',
                ], 410);
            }
        }

        $ubicacion = ChecadorUbicacion::where('id', $payload['ubicacion_id'])
            ->where('id_portal', $payload['id_portal'])
            ->where('id_cliente', $payload['id_cliente'])
            ->where('activa', 1)
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación del QR no existe o está inactiva.',
            ], 404);
        }
        $modosPermitidos = [
            'ninguno'  => [],
            'fijo'     => ['fijo'],
            'dinamico' => ['dinamico'],
            'ambos'    => ['fijo', 'dinamico'],
        ];

        $permitidos = $modosPermitidos[$ubicacion->qr_modo] ?? [];

        if (! in_array($modo, $permitidos, true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Este modo QR ya no está habilitado para la ubicación.',
            ], 422);
        }
        if ($modo === 'fijo') {
            $tokenHash = hash('sha256', $data['token']);

            if (
                empty($ubicacion->qr_token_fijo_hash) ||
                ! hash_equals($ubicacion->qr_token_fijo_hash, $tokenHash)
            ) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'El QR fijo fue revocado o ya no es válido.',
                ], 422);
            }
        }
        return response()->json([
            'ok'         => true,
            'qr_valido'  => true,
            'ubicacion'  => [
                'id'           => $ubicacion->id,
                'nombre'       => $ubicacion->nombre,
                'latitud'      => $ubicacion->latitud,
                'longitud'     => $ubicacion->longitud,
                'radio_metros' => $ubicacion->radio_metros,
            ],
            'expires_at' => $payload['expires_at'],
        ]);
    }
}
