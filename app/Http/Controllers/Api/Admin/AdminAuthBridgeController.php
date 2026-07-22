<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class AdminAuthBridgeController extends Controller
{
    public function exchange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'issuer'       => ['required', 'string', 'max:64'],
            'user_id'      => ['required', 'integer', 'min:1'],
            'portal_id'    => ['required', 'integer', 'min:1'],
            'role_id'      => ['required', 'integer', 'min:1'],
            'timestamp'    => ['required', 'integer'],
            'nonce'        => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'session_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Solicitud de autenticación inválida.',
            ], 422);
        }

        $data = $validator->validated();

        $secret = (string) config('services.ci3_bridge.secret');
        $issuer = (string) config('services.ci3_bridge.issuer');

        if (strlen($secret) < 64 || $issuer === '') {
            \Log::critical('Configuración CI3 Bridge incompleta.');

            return response()->json([
                'status'  => false,
                'message' => 'Servicio de autenticación no disponible.',
            ], 503);
        }

        if (! hash_equals($issuer, $data['issuer'])) {
            return $this->unauthorized();
        }

        $tolerance = (int) config(
            'services.ci3_bridge.timestamp_tolerance',
            60
        );

        if (abs(time() - (int) $data['timestamp']) > $tolerance) {
            return $this->unauthorized();
        }

        $receivedSignature = (string) $request->header(
            'X-CI3-Signature',
            ''
        );

        if (! preg_match('/\A[a-f0-9]{64}\z/', $receivedSignature)) {
            return $this->unauthorized();
        }

        $canonical = $this->canonicalString($data);

        $expectedSignature = hash_hmac(
            'sha256',
            $canonical,
            $secret
        );

        if (! hash_equals($expectedSignature, $receivedSignature)) {
            return $this->unauthorized();
        }

        /*
         * El nonce se consume después de validar la firma.
         * Cache::add() solamente funciona la primera vez.
         */
        $nonceKey = 'ci3_bridge_nonce:' . hash(
            'sha256',
            $data['issuer'] . '|' . $data['nonce']
        );

        $nonceAccepted = Cache::add(
            $nonceKey,
            true,
            now()->addMinutes(5)
        );

        if (! $nonceAccepted) {
            return $this->unauthorized();
        }

        $administrador = AdministradorAuth::query()
            ->with(['datosGenerales', 'portal', 'rol'])
            ->whereKey((int) $data['user_id'])
            ->where('id_portal', (int) $data['portal_id'])
            ->where('id_rol', (int) $data['role_id'])
            ->where('status', 1)
            ->where('eliminado', 0)
            ->first();

        if (
            ! $administrador ||
            ! $administrador->portal ||
            (int) $administrador->portal->status !== 1 ||
            (int) $administrador->portal->bloqueado !== 0 ||
            (int) $administrador->portal->com360 !== 1 ||
            ! $administrador->rol ||
            (int) $administrador->rol->status !== 1 ||
            (int) $administrador->rol->eliminado !== 0
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'El administrador no tiene acceso habilitado.',
            ], 403);
        }

        $tokenName = 'ci3_admin:' . $data['session_hash'];

        // Evita varios tokens activos para la misma sesión de CI3.
        $administrador->tokens()
            ->where('name', $tokenName)
            ->delete();

        $expiresAt = now()->addMinutes(
            (int) config('services.ci3_bridge.token_ttl', 15)
        );

        /*
         * Capacidad inicial. Después se sustituirá por los permisos
         * administrativos reales del checador.
         */
        $newToken = $administrador->createToken(
            $tokenName,
            ['admin:session'],
            $expiresAt
        );

        \Log::info('Token administrativo emitido mediante CI3 Bridge', [
            'admin_id'   => $administrador->id,
            'portal_id'  => $administrador->id_portal,
            'role_id'    => $administrador->id_rol,
            'token_id'   => $newToken->accessToken->id,
            'expires_at' => $expiresAt->toIso8601String(),
            'ip'         => $request->ip(),
        ]);

        return response()->json([
            'status'       => true,
            'token_type'   => 'Bearer',
            'access_token' => $newToken->plainTextToken,
            'expires_at'   => $expiresAt->toIso8601String(),
            'usuario'      => [
                'id'        => $administrador->id,
                'nombre'    => $administrador->nombre,
                'correo'    => $administrador->correo,
                'portal_id' => $administrador->id_portal,
                'role_id'   => $administrador->id_rol,
                'role'      => $administrador->rol->nombre,
            ],
        ]);
    }
    public function logout(Request $request): JsonResponse
    {
        $administrador = $request->user();

        /*
     * Impide que otro tipo de usuario autenticado, por ejemplo un
     * empleado, utilice este endpoint administrativo.
     */
        if (! $administrador instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $currentToken = $administrador->currentAccessToken();

        if ($currentToken) {
            $tokenId = $currentToken->id;

            $currentToken->delete();

            \Log::info('Token administrativo revocado', [
                'admin_id'  => $administrador->id,
                'portal_id' => $administrador->id_portal,
                'token_id'  => $tokenId,
                'ip'        => $request->ip(),
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Token administrativo revocado correctamente.',
        ]);
    }
    private function canonicalString(array $data): string
    {
        return implode("\n", [
            'POST',
            '/api/admin/auth/exchange',
            (string) $data['issuer'],
            (string) $data['user_id'],
            (string) $data['portal_id'],
            (string) $data['role_id'],
            (string) $data['timestamp'],
            (string) $data['nonce'],
            (string) $data['session_hash'],
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'message' => 'Solicitud de autenticación no autorizada.',
        ], 401);
    }
}
