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

        // ✅ RESPONDER OPTIONS SIEMPRE
        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204)
                ->withHeaders([
                    'Access-Control-Allow-Origin'      => $origin && in_array($origin, $allowedOrigins) ? $origin : '*',
                    'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS',
                    'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-CSRF-TOKEN, X-Portal-Id',
                    'Access-Control-Allow-Credentials' => 'true',
                ]);
        }

        // ⬇️ DEJAR PASAR EL REQUEST
        $response = $next($request);

        // ✅ AGREGAR HEADERS AL FINAL
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
