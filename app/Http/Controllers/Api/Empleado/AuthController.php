<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Auth\EmpleadoAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'correo'   => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Datos inválidos',
            ], 422);
        }

        $empleado = EmpleadoAuth::where('correo', $request->correo)
            ->where('status', 1)
            ->where('eliminado', 0)
            ->first();

        if (! $empleado) {
            return response()->json([
                'status'  => false,
                'message' => 'Usuario incorrecto ',
            ], 404);
        }

        // 🔐 Verificar si está bloqueado
        if ($empleado->locked_until && now()->lt($empleado->locked_until)) {
            return response()->json([
                'status'        => false,
                'message'       => 'Cuenta bloqueada temporalmente.',
                'blocked'       => true,
                'blocked_until' => $empleado->locked_until,
                'attempts_left' => 0,
            ], 423);
        }

        // 🔐 Validar contraseña
        if (! Hash::check($request->password, $empleado->password)) {

            $empleado->login_attempts += 1;

            $attemptsLeft = 5 - $empleado->login_attempts;

            if ($empleado->login_attempts >= 5) {
                $empleado->locked_until   = now()->addMinutes(15);
                $empleado->login_attempts = 0;
            }

            $empleado->save();

            return response()->json([
                'status'        => false,
                'message'       => 'Credenciales incorrectas',
                'attempts_left' => max($attemptsLeft, 0),
                'blocked'       => $empleado->locked_until ? true : false,
                'blocked_until' => $empleado->locked_until,
            ], 401);
        }
        // 🔐 Validar restricción por IP autorizada
        $ipCliente  = $request->ip();
        \Log::info('=== LOGIN EMPLEADO ===', [
            'ip_request'       => $request->ip(),
            'remote_addr'      => $_SERVER['REMOTE_ADDR'] ?? null,
            'x_forwarded_for'  => $request->header('X-Forwarded-For'),
            'cf_connecting_ip' => $request->header('CF-Connecting-IP'),
            'user_agent'       => $request->userAgent(),
        ]);
        $ipsAutorizadas  = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->where('activo', 1)
            ->pluck('ip')
            ->map(fn($ip) => trim((string) $ip))
            ->filter()
            ->values();

        if ($ipsAutorizadas->isNotEmpty() && ! $ipsAutorizadas->contains($ipCliente)) {
            return response()->json([
                'status'  => false,
                'message' => 'Acceso no autorizado desde esta dirección IP.',
                'code'    => 'IP_NOT_AUTHORIZED',
            ], 403);
        }
        // Validar suspensión del portal por falta de pago
        $accesoPago = app(
            \App\Services\Portal\PortalPaymentAccessService::class
        );

        if (! $accesoPago->permiteAcceso((int) $empleado->id_portal)) {
            return response()->json([
                'status'  => false,
                'code'    => 'PORTAL_SUSPENDIDO',
                'message' => 'El servicio de tu empresa se encuentra temporalmente suspendido. Comunícate con el administrador de tu empresa.',
            ], 403);
        }
        // ✅ Login correcto → resetear intentos
        $empleado->login_attempts = 0;
        $empleado->locked_until   = null;
        $empleado->last_login_at  = now();
        $empleado->last_login_ip  = $ipCliente;
        // Recordar sesion solo en navegadores moviles,
        // con consentimiento y funcionalidad habilitada.
        $rememberMobile = (bool) config('miportal_refresh.enabled', false)
            && $request->boolean('remember_device')
            && preg_match(
                '/Android|iPhone|iPad|iPod|Mobile/i',
                (string) $request->userAgent()
            ) === 1
            && in_array(
                (string) $request->header('Origin', ''),
                config('miportal_refresh.allowed_origins', []),
                true
            );

        // Guardar el login y emitir su token en una sola transaccion.
        $session = \Illuminate\Support\Facades\DB::connection('portal_main')
            ->transaction(function () use ($empleado, $rememberMobile): array {

                // Mismo bloqueo utilizado por la revocacion y el refresh.
                $actual = \App\Models\Auth\EmpleadoAuth::query()
                    ->whereKey($empleado->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Evitar emitir un token si la cuenta o la
                // contrasena cambiaron durante el login.
                if (
                    (int) $actual->status !== 1
                    || (int) $actual->eliminado !== 0
                    || ! hash_equals(
                        (string) $empleado->password,
                        (string) $actual->password
                    )
                ) {
                    abort(401, 'Las credenciales cambiaron. Inicia sesion nuevamente.');
                }

                $actual->login_attempts = 0;
                $actual->locked_until = null;
                $actual->last_login_at = $empleado->last_login_at;
                $actual->last_login_ip = $empleado->last_login_ip;
                $actual->save();

                // Revocacion y creacion dentro de la misma transaccion.
                app(\App\Services\Auth\EmpleadoSessionService::class)
                    ->revokeAllAccessTokens($actual);

                $accessToken = $actual
                    ->createToken('empleado_token')
                    ->plainTextToken;

                // Nunca emitir refresh si requiere cambio de clave.
                $refresh = (
                    $rememberMobile
                    && ! (bool) $actual->force_password_change
                )
                    ? app(\App\Services\Auth\EmpleadoRefreshTokenService::class)
                        ->issue($actual)
                    : null;

                return [
                    'access_token' => $accessToken,
                    'refresh_token' => $refresh?->plainTextToken,
                    'refresh_expires_at' =>
                        $refresh?->accessToken->expires_at,
                ];
            }, 3);

        $token = $session['access_token'];

        $response = response()->json([
            'status'       => true,
            'access_token' => $token,
            'remember_device_enabled' => $session['refresh_token'] !== null,
            'usuario'      => [
                'id'                    => $empleado->id,
                'nombre'                => $empleado->nombre,
                'correo'                => $empleado->correo,
                'portal_id'             => $empleado->id_portal,
                'cliente_id'            => $empleado->id_cliente,
                'foto'                  => $empleado->foto,
                'force_password_change' => $empleado->force_password_change,
            ],
        ]);

        if ($session['refresh_token'] !== null) {
            $response->withCookie(
                app(\App\Services\Auth\EmpleadoRefreshCookieService::class)
                    ->create(
                        $session['refresh_token'],
                        $session['refresh_expires_at']
                    )
            );
        }

        if ($session['refresh_token'] === null) {
            $response->withCookie(
                app(\App\Services\Auth\EmpleadoRefreshCookieService::class)
                    ->clear()
            );
        }

        return $response->header('Cache-Control', 'no-store');
    }
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {

            $tokenId = $user->currentAccessToken()->id;

            app(\App\Services\Auth\EmpleadoSessionService::class)->revokeAllAccessTokens($user);

            \Log::info('Empleado logout', [
                'user_id'    => $user->id,
                'token_id'   => $tokenId,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Sesión cerrada correctamente',
        ])->withCookie(
            app(\App\Services\Auth\EmpleadoRefreshCookieService::class)->clear()
        );
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password'     => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)
                    ->mixedCase() // mayúsculas y minúsculas
                    ->numbers()   // al menos un número
                    ->symbols(),  // al menos un símbolo
            ],
        ]);
        $validator->setCustomMessages([
            'new_password.different' => 'La nueva contraseña no puede ser igual a la actual.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $empleado = $request->user();

// Validar suspensión del portal por falta de pago
        $accesoPago = app(
            \App\Services\Portal\PortalPaymentAccessService::class
        );

        if (! $accesoPago->permiteAcceso((int) $empleado->id_portal)) {
            return response()->json([
                'status'  => false,
                'code'    => 'PORTAL_SUSPENDIDO',
                'message' => 'El servicio de tu empresa se encuentra temporalmente suspendido. Comunícate con el administrador de tu empresa.',
            ], 403);
        }

// Verificar contraseña actual
        if (! Hash::check($request->current_password, $empleado->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Contraseña actual incorrecta',
            ], 422);
        }

        // Cambio de contrasena y revocacion atomicos.
        $token = \Illuminate\Support\Facades\DB::connection('portal_main')
            ->transaction(function () use ($request, $empleado): string {

                $actual = \App\Models\Auth\EmpleadoAuth::query()
                    ->whereKey($empleado->getKey())
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $actual
                    || (int) $actual->status !== 1
                    || (int) $actual->eliminado !== 0
                ) {
                    abort(401, 'La cuenta ya no esta disponible.');
                }

                // Revalidar contra la contrasena actual en BD.
                if (! Hash::check(
                    $request->current_password,
                    $actual->password
                )) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json([
                            'status' => false,
                            'message' => 'Contraseña actual incorrecta',
                        ], 422)
                    );
                }

                $actual->password = Hash::make($request->new_password);
                $actual->force_password_change = 0;
                $actual->password_changed_at = now();
                $actual->save();

                // Revocar tambien las futuras credenciales refresh.
                app(\App\Services\Auth\EmpleadoSessionService::class)
                    ->revokeAllAccessTokens($actual);

                return $actual
                    ->createToken('empleado_token')
                    ->plainTextToken;
            }, 3);

        return response()->json([
            'status'       => true,
            'access_token' => $token,
            'message'      => 'Contraseña actualizada correctamente',
        ]);
    }
}
