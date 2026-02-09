<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');

        $allowedOrigins = [
            'https://portal.talentsafecontrol.com',
            'https://rodicontrol.rodi.com.mx',
            'http://localhost',
            'http://localhost:8080',
            'http://localhost:8000',
            'http://localhost:5173',
        ];

        /*
         * 1️⃣ PRE-FLIGHT OPTIONS
         * Siempre responder 200, aunque no haya Origin
         */
        if ($request->isMethod('options')) {
            $response = response()->json([], 200);

            if ($origin && in_array($origin, $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }

            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set(
                'Access-Control-Allow-Headers',
                'Content-Type, Authorization, X-CSRF-TOKEN, X-Portal-Id'
            );

            return $response;
        }

        /*
         * 2️⃣ REQUEST NORMAL (POST, GET, etc.)
         */
        $response = $next($request);

        /*
         * 3️⃣ Agregar headers SOLO si hay Origin válido
         * (backend-to-backend no los necesita)
         */
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set(
                'Access-Control-Allow-Headers',
                'Content-Type, Authorization, X-CSRF-TOKEN, X-Portal-Id'
            );
        }

        return $response;
    }
}
