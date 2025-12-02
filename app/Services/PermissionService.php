<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PermissionService
{
    /** @var string */
    protected $connection = 'portal_main';   // 👈 usa SIEMPRE esta conexión

    /** @var string */
    protected $permTable;

    /** @var string */
    protected $userPermTable;

    public function __construct()
    {
        // Nombres de tabla configurables por .env, pero con default:
        $this->permTable     = env('PERMS_TABLE_PERM', 'auth_permission');
        $this->userPermTable = env('PERMS_TABLE_USER', 'auth_user_permission');
    }

    public function getEffectivePermissions(int $userId, string $module): array
    {
        // 1) Permisos base activos del módulo (catálogo)
        $basePerms = DB::connection($this->connection)
            ->table($this->permTable)
            ->where('module', $module)
            ->where('is_active', 1)
            ->pluck('key')
            ->toArray();

        // 2) Overrides de usuario (allow / deny)
        $userPerms = DB::connection($this->connection)
            ->table($this->userPermTable)
            ->where('user_id', $userId)
            ->where(function ($q) use ($module) {
                $q->where('permission_key', 'like', $module . '.%')
                  ->orWhere('permission_key', $module); // por si guardas el módulo exacto
            })
            ->where(function ($q) {
                $q->where('scope_type', 'global')
                  ->orWhereNull('scope_type');
            })
            ->select('permission_key', 'effect') // effect: allow | deny
            ->get();

        $grants = [];
        $denies = [];

        foreach ($userPerms as $row) {
          if ($row->effect === 'allow') {
              $grants[] = $row->permission_key;
          } elseif ($row->effect === 'deny') {
              $denies[] = $row->permission_key;
          }
        }

        // 3) Efectivo = (base + grants) - denies
        $effective = array_values(
            array_diff(
                array_unique(array_merge($basePerms, $grants)),
                $denies
            )
        );

        return [
            'grants'    => array_values(array_unique($grants)),
            'denies'    => array_values(array_unique($denies)),
            'effective' => $effective,
        ];
    }
}
