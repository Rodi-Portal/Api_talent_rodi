<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Si no está autenticado, seguir
        if (!$user) {
            return $next($request);
        }

        // Permitir acceso al endpoint de cambio de contraseña
        if ($request->is('api/empleado/auth/change-password')) {
            return $next($request);
        }

        // Si debe cambiar contraseña → bloquear
        if ($user->force_password_change) {
            return response()->json([
                'status' => false,
                'message' => 'Debe cambiar su contraseña antes de continuar.',
                'force_password_change' => true
            ], 403);
        }

        return $next($request);
    }
}