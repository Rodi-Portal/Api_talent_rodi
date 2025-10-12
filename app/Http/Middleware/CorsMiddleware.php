<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Lista de orígenes permitidos
        $allowedOrigins = [
            'https://portal.talentsafecontrol.com',
            'https://rodicontrol.rodi.com.mx',
            'http://localhost',
            'http://localhost:8080',
            'http://localhost:8000',
            'http://localhost:5173',
        ];

        $origin = $request->headers->get('Origin');

        // Permite cualquier origen para debug local (opcional)
        $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : null;

        // Responder a OPTIONS (preflight) primero
        if ($request->isMethod('OPTIONS')) {
            $response = response()->json([], 200);
        } else {
            $response = $next($request);
        }

        // Agregar cabeceras CORS si el origen está permitido
        if ($allowOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
