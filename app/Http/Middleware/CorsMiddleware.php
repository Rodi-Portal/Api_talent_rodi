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
            'https://portal.talentsafecontrol.com',
            'https://rodicontrol.rodi.com.mx',
            'http://localhost',
            'http://localhost:8080',
        ];
    
        \Log::info("CORS Middleware - Origin: $origin");  // Log para ver qué origen llega
    
        // Si la solicitud es de tipo OPTIONS, retorna una respuesta con los headers de CORS
        if ($request->isMethod('options')) {
            \Log::info('CORS OPTIONS Request');  // Log para solicitudes OPTIONS
            return response()
                ->json([], 200)
                ->header('Access-Control-Allow-Origin', in_array($origin, $allowedOrigins) ? $origin : '')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-TOKEN');
        }
    
        // Procesa la solicitud y agrega los encabezados CORS en la respuesta
        $response = $next($request);
    
        // Log de los encabezados que se están agregando
        if (in_array($origin, $allowedOrigins)) {
            \Log::info("CORS Allowed Origin: $origin");
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-CSRF-TOKEN');
        }
    
        return $response;
    }
}
