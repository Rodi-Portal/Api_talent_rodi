<?php

namespace App\Services\Auth;

use App\Models\Auth\AdministradorAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class AdminClientScopeService
{
    private string $connection = 'portal_main';

    /**
     * Obtiene las sucursales permitidas para el usuario y portal
     * autenticados, usando la misma fuente que CI3: usuario_permiso.
     */
    public function permittedClientIds(
        AdministradorAuth $administrator
    ): array {
        return DB::connection($this->connection)
            ->table('usuario_permiso as up')
            ->join('cliente as c', 'c.id', '=', 'up.id_cliente')
            ->where('up.id_usuario', (int) $administrator->id)
            ->where('c.id_portal', (int) $administrator->id_portal)
            ->distinct()
            ->pluck('c.id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Valida que todas las sucursales solicitadas estén permitidas.
     *
     * @throws AuthorizationException
     */
    public function authorizeRequestedClients(
        AdministradorAuth $administrator,
        array $requestedClientIds
    ): array {
        $requested = collect($requestedClientIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($requested === []) {
            throw new AuthorizationException(
                'No se proporcionaron sucursales válidas.'
            );
        }

        $permitted = $this->permittedClientIds($administrator);

        $unauthorized = array_values(
            array_diff($requested, $permitted)
        );

        if ($unauthorized !== []) {
            throw new AuthorizationException(
                'Una o más sucursales no están permitidas para este usuario.'
            );
        }

        return $requested;
    }
}