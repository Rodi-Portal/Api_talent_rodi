<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Auth\EmpleadoAuth;
use Illuminate\Http\Request;
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
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        // 🔐 Verificar si está bloqueado
        if ($empleado->locked_until && now()->lt($empleado->locked_until)) {
            return response()->json([
                'status'  => false,
                'message' => 'Cuenta bloqueada temporalmente. Intenta más tarde.',
            ], 423);
        }

        // 🔐 Validar contraseña
        if (! Hash::check($request->password, $empleado->password)) {

            $empleado->login_attempts += 1;

            // 🔥 Bloquear después de 5 intentos
            if ($empleado->login_attempts >= 5) {
                $empleado->locked_until   = now()->addMinutes(15);
                $empleado->login_attempts = 0;
            }

            $empleado->save();

            return response()->json([
                'status'  => false,
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        // ✅ Login correcto → resetear intentos
        $empleado->login_attempts = 0;
        $empleado->locked_until   = null;
        $empleado->last_login_at  = now();
        $empleado->last_login_ip  = $request->ip();
        $empleado->save();

        // Revocar tokens anteriores
        $empleado->tokens()->delete();

        $token = $empleado->createToken('empleado_token')->plainTextToken;

        return response()->json([
            'status'       => true,
            'access_token' => $token,
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
    }
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Sesión cerrada',
        ]);
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
        if (! Hash::check($request->current_password, $empleado->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Contraseña actual incorrecta',
            ], 422);
        }

        $empleado->password              = Hash::make($request->new_password);
        $empleado->force_password_change = 0;
        $empleado->password_changed_at   = now();
        $empleado->save();

        // 🔥 Revocar todos los tokens previos
        $empleado->tokens()->delete();

        $token = $empleado->createToken('empleado_token')->plainTextToken;

        return response()->json([
            'status'       => true,
            'access_token' => $token,
            'message'      => 'Contraseña actualizada correctamente',
        ]);
    }
}
