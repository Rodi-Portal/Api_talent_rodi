<?php

namespace App\Services\Auth;

use App\Models\Auth\EmpleadoAuth;
use Laravel\Sanctum\PersonalAccessToken;

final class EmpleadoSessionService
{
    /**
     * Revoca todos los tokens de acceso del colaborador.
     *
     * Retorna la cantidad de tokens eliminados.
     */
    public function revokeAllAccessTokens(EmpleadoAuth $empleado): int
    {
        return \Illuminate\Support\Facades\DB::connection('portal_main')
            ->transaction(function () use ($empleado): int {
                // Bloqueo individual del colaborador.
                EmpleadoAuth::query()
                    ->whereKey($empleado->getKey())
                    ->lockForUpdate()
                    ->first();

                return $empleado->tokens()->delete();
            }, 3);
    }

    /**
     * Revoca solamente el token utilizado en la solicitud actual.
     *
     * Retorna el ID del token revocado o null.
     */
    public function revokeCurrentAccessToken(EmpleadoAuth $empleado): ?int
    {
        $token = $empleado->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $tokenId = (int) $token->getKey();

        $token->delete();

        return $tokenId;
    }



    /**
     * Revoca todos los tokens de un colaborador mediante su ID.
     *
     * Util para operaciones que trabajan con Query Builder.
     */
    public function revokeAllAccessTokensById(int $empleadoId): int
    {
        if ($empleadoId <= 0) {
            throw new \InvalidArgumentException(
                'El ID del empleado debe ser positivo.'
            );
        }

        return \Illuminate\Support\Facades\DB::connection('portal_main')
            ->transaction(function () use ($empleadoId): int {
                // Mantener el mismo orden de bloqueo.
                EmpleadoAuth::query()
                    ->whereKey($empleadoId)
                    ->lockForUpdate()
                    ->first();

                return \App\Models\Auth\PersonalAccessToken::query()
                    ->where(
                        'tokenable_type',
                        (new EmpleadoAuth())->getMorphClass()
                    )
                    ->where('tokenable_id', $empleadoId)
                    ->delete();
            }, 3);
    }
}