<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Obtener el origen de la solicitud
        $origin = $request->headers->get('Origin');

        // Lista de orígenes permitidos
        $allowedOrigins = [
            'https://sandbox.talentsafecontrol.com',
            'https://rodicontrol.rodi.com.mx',
            'http://localhost',
            'http://localhost:8080',
            'http://localhost:8000',
            'http://localhost:5173',
            //   'http://localhost:8001',
        ];

        //\Log::info("CORS Middleware - Origin received: $origin");

        // Si la solicitud es de tipo OPTIONS, responde y detén el procesamiento
        if ($request->isMethod('options')) {
            //   \Log::info('CORS OPTIONS Request');
            if (in_array($origin, $allowedOrigins)) {
                return response()
                    ->json([], 200)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-TOKEN, X-Portal-Id')
                    ->header('Access-Control-Allow-Credentials', 'true'); // Si necesitas credenciales
            }
            // Si el origen no está permitido, responde sin encabezados
            return response()->json([], 403);
        }

        // Procesa la solicitud principal
        $response = $next($request);

        // Agregar encabezados CORS si el origen está permitido
        if (in_array($origin, $allowedOrigins)) {
            //  \Log::info("CORS Allowed Origin: $origin");
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-TOKEN, X-Portal-Id');
            $response->headers->set('Access-Control-Allow-Credentials', 'true'); // Si necesitas credenciales
        }

        return $response;
    }
}

