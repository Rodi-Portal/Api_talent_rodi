<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    public function __construct(private string $conn = 'portal_main') {}

    private function db()
    {
        return DB::connection($this->conn);
    }

    /**
     * Evalúa permiso con precedencia:
     * user deny > user allow > role deny > role allow > fallback operativo (roles 1,6,9,10) > deny
     */
 public function can(int $userId, int $roleId, string $key, ?int $clientId = null): bool
{
    $cacheKey = "perm:{$this->conn}:u{$userId}:r{$roleId}:k{$key}:c" . ($clientId ?? 'all');

    return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($userId, $roleId, $key, $clientId) {

        // 1) USER override
        $u = $this->getEffect('auth_user_permission', 'user_id', $userId, $key, $clientId);
        if ($u === 'deny')  return false;
        if ($u === 'allow') return true;

        // 2) Fallback por rol operativo
        if (in_array($roleId, [1, 6, 9, 10], true) && $this->isOperativeKey($key)) {
            return true;
        }

        return false;
    });
}


  private function isOperativeKey(string $key): bool
{
    return str_starts_with($key, 'dashboard.')
        || str_starts_with($key, 'operativo.')
        || str_starts_with($key, 'module.')          // ✅ CLAVE para modules_effective
        || str_starts_with($key, 'comunicacion.')
        || str_starts_with($key, 'empleados.')
        || str_starts_with($key, 'former.')
        || str_starts_with($key, 'reclutamiento.')
        || str_starts_with($key, 'pre_empleo.');
}


    /**
     * Lee effect en una tabla de permisos con scope.
     * Scope simple:
     * - acepta global (__GLOBAL__)
     * - si hay clientId, acepta también scope_type='client' y scope_value=clientId
     *
     * Si tu sistema maneja otros scopes, aquí lo extiendes.
     */
    private function getEffect(string $table, string $idCol, int $id, string $key, ?int $clientId): ?string
    {
        // Si la tabla no existe, regresamos null
        try {
            $q = $this->db()->table($table)
                ->where($idCol, $id)
                ->where('permission_key', $key);

            $q->where(function ($w) use ($clientId) {
                // global
                $w->where('scope_type', 'global');

                // client scope (si aplica)
                if ($clientId) {
                    $w->orWhere(function ($w2) use ($clientId) {
                        $w2->where('scope_type', 'client')
                           ->where('scope_value', $clientId);
                    });
                }
            });

            // Si hay varios, nos interesa el más “fuerte”.
            // deny primero, luego allow.
            $rows = $q->pluck('effect')->map(fn($v) => strtolower((string)$v))->all();

            if (in_array('deny', $rows, true))  return 'deny';
            if (in_array('allow', $rows, true)) return 'allow';

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
