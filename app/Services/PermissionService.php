<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PermissionService
{
    public function getEffectivePermissions(int $userId, string $module): array
    {
        $conn = env('PERMS_CONNECTION', config('database.default'));
        $permTable = env('PERMS_TABLE_PERM', 'auth_permission');
        $userPermTable = env('PERMS_TABLE_USER', 'auth_user_permission');

        // 1) Cargar las keys activas del módulo
        $basePerms = DB::connection($conn)
            ->table($permTable)
            ->where('module', $module)
            ->where('is_active', 1)
            ->pluck('key')
            ->toArray();

        // 2) Cargar grants/denies del usuario para ese módulo (scope global)
        $userPerms = DB::connection($conn)
            ->table($userPermTable)
            ->where('user_id', $userId)
            ->where(function ($q) use ($module) {
                $q->where('permission_key', 'like', $module . '.%')
                  ->orWhere('permission_key', 'like', $module); // por si guardas exacto
            })
            ->where(function ($q) {
                $q->where('scope_type', 'global')
                  ->orWhereNull('scope_type');
            })
            ->select('permission_key', 'effect') // effect: allow|deny
            ->get();

        $grants = [];
        $denies = [];

        foreach ($userPerms as $row) {
            if ($row->effect === 'allow') $grants[] = $row->permission_key;
            if ($row->effect === 'deny')  $denies[] = $row->permission_key;
        }

        // 3) Efectivo = (base + grants) - denies
        $effective = array_values(array_diff(array_unique(array_merge($basePerms, $grants)), $denies));

        return [
            'grants'    => array_values(array_unique($grants)),
            'denies'    => array_values(array_unique($denies)),
            'effective' => $effective,
        ];
    }
}
