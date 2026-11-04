<?php

namespace App\Services\Auth;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class EmpleadoRefreshCookieService
{
    private const NAME = 'miportal_refresh';
    private const PATH = '/api/empleado/auth';

    /**
     * Cookie exclusiva para la credencial de renovacion.
     *
     * Sin Domain: pertenece solamente al host de la API.
     * Secure: requiere HTTPS, incluido el proxy local de Vite.
     */
    public function create(
        string $plainToken,
        CarbonInterface $expiresAt
    ): Cookie {
        return Cookie::create(
            self::NAME,
            $plainToken,
            $expiresAt,
            self::PATH,
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    /**
     * Leer la credencial sin utilizar Authorization Bearer.
     */
    public function read(Request $request): ?string
    {
        $value = $request->cookie(self::NAME);

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * Eliminar la cookie al cerrar sesion.
     */
    public function clear(): Cookie
    {
        return Cookie::create(
            self::NAME,
            '',
            1,
            self::PATH,
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }
}