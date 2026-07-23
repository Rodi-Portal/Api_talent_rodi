<?php

namespace App\Http\Middleware;

use App\Models\Auth\AdministradorAuth;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminSessionToken
{
    public function handle(Request $request, Closure $next)
    {
        $administrador = $request->user();

        if (! $administrador instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $token = $administrador->currentAccessToken();

        if (! $token || ! $administrador->tokenCan('admin:session')) {
            return response()->json([
                'status'  => false,
                'message' => 'El token no tiene acceso administrativo.',
            ], 403);
        }

        return $next($request);
    }
}