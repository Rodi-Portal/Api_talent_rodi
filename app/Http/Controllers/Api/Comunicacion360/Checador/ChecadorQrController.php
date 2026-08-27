<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Checador\ChecadorQrValidationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ChecadorQrController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {}
    public function generar(Request $request)
    {
        $data = $request->validate([
            'ubicacion_id' => ['required', 'integer'],
            'modo'         => ['nullable', 'in:fijo,dinamico'],
        ]);
        $administrator = $this->administrator($request);
        $ubicacion     = ChecadorUbicacion::query()
            ->where(
                'id',
                (int) $data['ubicacion_id']
            )
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('activa', 1)
            ->first();
        $modo = $data['modo'] ?? 'dinamico';
        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación no existe o está inactiva.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $ubicacion->id_cliente]
        );
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

        $this->auditoria->registrar([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   =>
            (int) $ubicacion->id_cliente,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'ubicacion_checada_qr',
            'entidad_id'   => (int) $ubicacion->id,
            'accion'       => 'generar',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            'QR de ubicación generado.',
            'datos_nuevos' => [
                'modo'               => $modo,
                'expira_en_segundos' =>
                $modo === 'dinamico'
                    ? (int) ($ubicacion->qr_expira_segundos ?: 60)
                    : null,
                'qr_actualizado_en'  =>
                $modo === 'fijo'
                    ? now()->toIso8601String()
                    : null,
            ],
        ], $request);
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

        $administrator = $this->administrator($request);
        $ubicacion     = ChecadorUbicacion::query()
            ->where(
                'id',
                (int) $ubicacionId
            )
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación no existe en el contexto solicitado.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $ubicacion->id_cliente]
        );
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
        $this->auditoria->registrar([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   =>
            (int) $ubicacion->id_cliente,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'ubicacion_checada_qr',
            'entidad_id'   => (int) $ubicacion->id,
            'accion'       => 'visualizar',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            'QR fijo de ubicación visualizado.',
            'metadatos'    => [
                'modo'              => 'fijo',
                'ubicacion_nombre'  =>
                $ubicacion->nombre,
                'qr_actualizado_en' =>
                optional($ubicacion->qr_actualizado_en)
                    ->toIso8601String(),
            ],
        ], $request);
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

    public function validar(
        Request $request,
        ChecadorQrValidationService $qrValidationService
    ) {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $resultado = $qrValidationService->validar($data['token']);

        if (! $resultado['ok']) {
            return response()->json([
                'ok'      => false,
                'code'    => $resultado['code'],
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        $ubicacion = $resultado['ubicacion'];
        $payload   = $resultado['payload'];

        return response()->json([
            'ok'         => true,
            'code'       => 'valid_qr',
            'qr_valido'  => true,
            'modo'       => $payload['modo'],
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
    private function administratorName(
        AdministradorAuth $administrator
    ): string {
        return trim(collect([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])->filter()->implode(' '));
    }

    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}
