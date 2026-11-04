<?php

namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Services\Auth\EmpleadoRefreshCookieService;
use App\Services\Auth\EmpleadoRefreshTokenService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmpleadoRefreshController extends Controller
{
    public function refresh(
        Request $request,
        EmpleadoRefreshTokenService $tokens,
        EmpleadoRefreshCookieService $cookies
    ): JsonResponse {
        if (! config('miportal_refresh.enabled', false)) {
            return response()->json([
                'message' => 'Not Found',
            ], 404);
        }

        // No aceptar solicitudes de otros sitios.
        $origin = (string) $request->header('Origin', '');
        $allowed = config('miportal_refresh.allowed_origins', []);

        if (
            $origin === ''
            || ! in_array($origin, $allowed, true)
        ) {
            return response()->json([
                'message' => 'Origen no autorizado',
            ], 403);
        }

        // Exigir JSON para evitar solicitudes HTML simples.
        // Junto con Origin estricto y CORS, mitiga CSRF.
        if (! $request->isJson()) {
            return response()->json([
                'message' => 'Content-Type no admitido',
            ], 415);
        }

        $fetchSite = $request->header('Sec-Fetch-Site');

        if (
            $fetchSite
            && ! in_array(
                $fetchSite,
                ['same-origin', 'same-site'],
                true
            )
        ) {
            return response()->json([
                'message' => 'Solicitud no autorizada',
            ], 403);
        }

        $plainToken = $cookies->read($request);

        if (! $plainToken) {
            return response()->json([
                'message' => 'Sesion no renovable',
            ], 401);
        }

        $result = $tokens->rotate($plainToken, $request);

        if (! $result) {
            // No borrar aqui la cookie: otra pestana podria
            // haber completado una renovacion simultanea.
            return response()->json([
                'message' => 'Sesion no renovable',
            ], 401);
        }

        return response()->json([
            'status' => true,
            'access_token' => $result['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => 60 * 60,
        ])->withCookie(
            $cookies->create(
                $result['refresh_token'],
                Carbon::parse($result['refresh_expires_at'])
            )
        )->header('Cache-Control', 'no-store');
    }
}