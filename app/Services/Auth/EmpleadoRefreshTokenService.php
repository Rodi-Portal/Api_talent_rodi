<?php

namespace App\Services\Auth;

use App\Models\Auth\EmpleadoAuth;
use App\Models\Auth\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

final class EmpleadoRefreshTokenService
{
    private const TOKEN_NAME = 'miportal_refresh';
    private const TOKEN_ABILITY = 'miportal:refresh';
    private const REFRESH_DAYS = 7;

    /**
     * Emitir solo despues de un login autorizado.
     * El controlador decidira si el usuario opto
     * por recordar este navegador.
     */
    public function issue(EmpleadoAuth $empleado): NewAccessToken
    {
        return $empleado->createToken(
            self::TOKEN_NAME,
            [self::TOKEN_ABILITY],
            now()->addDays(self::REFRESH_DAYS)
        );
    }

    /**
     * Rotacion atomica de una credencial existente.
     *
     * No utiliza auth:empleado ni auth:sanctum.
     * No extiende la expiracion absoluta del refresh.
     *
     * @return array<string, string>|null
     */
    public function rotate(string $plainToken, Request $request): ?array
    {
        if (! preg_match(
            '/\A([1-9][0-9]{0,17})\|([A-Za-z0-9]{40,128})\z/',
            $plainToken,
            $matches
        )) {
            return null;
        }

        $tokenId = (int) $matches[1];
        $secret = $matches[2];

        return DB::connection('portal_main')->transaction(
            function () use ($tokenId, $secret, $request): ?array {
                // Lectura inicial sin bloqueo para identificar al empleado.
                $candidate = PersonalAccessToken::query()
                    ->whereKey($tokenId)
                    ->first(['id', 'tokenable_id', 'tokenable_type']);

                if (
                    ! $candidate
                    || $candidate->tokenable_type !==
                        (new EmpleadoAuth())->getMorphClass()
                ) {
                    return null;
                }

                // Orden de bloqueos: empleado primero, token despues.
                $empleado = EmpleadoAuth::query()
                    ->whereKey($candidate->tokenable_id)
                    ->lockForUpdate()
                    ->first();

                if (! $empleado) {
                    return null;
                }

                // Reconsultar el refresh despues de bloquear al empleado.
                $refresh = PersonalAccessToken::query()
                    ->whereKey($tokenId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $refresh
                    || (int) $refresh->tokenable_id !==
                        (int) $empleado->getKey()
                ) {
                    return null;
                }

                if (
                    $refresh->name !== self::TOKEN_NAME
                    || $refresh->abilities !== [self::TOKEN_ABILITY]
                    || $refresh->tokenable_type !==
                        (new EmpleadoAuth())->getMorphClass()
                ) {
                    return null;
                }

                if (
                    ! $refresh->expires_at
                    || $refresh->expires_at->isPast()
                ) {
                    return null;
                }

                if (! hash_equals(
                    (string) $refresh->token,
                    hash('sha256', $secret)
                )) {
                    return null;
                }

                if (
                    (int) $empleado->status !== 1
                    || (int) $empleado->eliminado !== 0
                    || (bool) $empleado->force_password_change
                    || (
                        $empleado->locked_until
                        && now()->lt($empleado->locked_until)
                    )
                ) {
                    return null;
                }

                // Aplicar las mismas restricciones IP del login.
                $ipsAutorizadas = DB::connection('portal_main')
                    ->table('empleado_ips_autorizadas')
                    ->where('id_portal', (int) $empleado->id_portal)
                    ->where('id_cliente', (int) $empleado->id_cliente)
                    ->where('id_empleado', (int) $empleado->id)
                    ->where('activo', 1)
                    ->pluck('ip')
                    ->map(fn ($ip) => trim((string) $ip))
                    ->filter()
                    ->values();

                if (
                    $ipsAutorizadas->isNotEmpty()
                    && ! $ipsAutorizadas->contains($request->ip())
                ) {
                    return null;
                }

                // Respetar la suspension del portal por pago.
                $accesoPago = app(
                    \App\Services\Portal\PortalPaymentAccessService::class
                );

                if (! $accesoPago->permiteAcceso(
                    (int) $empleado->id_portal
                )) {
                    return null;
                }

                // Reutilizamos la misma fila e ID.
                // Solo una solicitud puede rotar el hash anterior.
                $nuevoSecreto = bin2hex(random_bytes(32));

                $refresh->forceFill([
                    'token' => hash('sha256', $nuevoSecreto),
                    'last_used_at' => now(),
                ])->save();

                // Sustituir accesos anteriores del mismo colaborador.
                // Conservar el token miportal_refresh.
                PersonalAccessToken::query()
                    ->where(
                        'tokenable_type',
                        (new EmpleadoAuth())->getMorphClass()
                    )
                    ->where('tokenable_id', $empleado->getKey())
                    ->where('name', 'empleado_token')
                    ->delete();

                // El nuevo acceso conserva la expiracion de Sanctum.
                $access = $empleado
                    ->createToken('empleado_token')
                    ->plainTextToken;

                return [
                    'access_token' => $access,
                    'refresh_token' =>
                        $refresh->getKey() . '|' . $nuevoSecreto,
                    'refresh_expires_at' =>
                        $refresh->expires_at->toIso8601String(),
                ];
            },
            3
        );
    }
}