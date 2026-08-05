<?php
namespace App\Http\Middleware;

use App\Models\Auth\AdministradorAuth;
use App\Services\Auth\PermissionService;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminPermission
{
    public function __construct(
        private PermissionService $permissions
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string ...$permissions
    ) {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'code'    => 'ADMIN_TOKEN_INVALID',
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $allowed = collect($permissions)->contains(
            fn(string $permission) => $this->permissions->canAdminGlobal(
                (int) $administrator->id,
                (int) $administrator->id_rol,
                $permission
            )
        );

        if (! $allowed) {
            return response()->json([
                'status'          => false,
                'code'            => 'PERMISSION_DENIED',
                'message'         => 'No tienes permiso para realizar esta acción.',
                'permissions_any' => $permissions,
            ], 403);
        }

        return $next($request);
    }
}
