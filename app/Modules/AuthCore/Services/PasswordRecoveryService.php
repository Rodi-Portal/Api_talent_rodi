<?php
namespace App\Modules\AuthCore\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordRecoveryService
{
    protected string $connection = 'portal_main';

    /* ===============================
       RESOLVE USER EXISTENCE
    =============================== */

    public function userExists(string $email, string $guard): bool
    {
        if ($guard === 'empleado') {
            return DB::connection($this->connection)
                ->table('empleados')
                ->where('correo', $email)
                ->where('status', 1)
                ->where('eliminado', 0)
                ->exists();
        }

        if ($guard === 'admin') {
            return DB::connection($this->connection)
                ->table('datos_generales as dg')
                ->leftJoin('usuarios_portal as up', 'up.id_datos_generales', '=', 'dg.id')
                ->leftJoin('usuarios_clientes as uc', 'uc.id_datos_generales', '=', 'dg.id')
                ->where('dg.correo', $email)
                ->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('up.status', 1)
                            ->where('up.eliminado', 0);
                    })
                        ->orWhere(function ($sub) {
                            $sub->where('uc.status', 1)
                                ->where('uc.eliminado', 0);
                        });
                })
                ->exists();
        }

        return false;
    }

    /* ===============================
       GENERATE OTP
    =============================== */

    public function generateOtp(string $email, string $guard): string
    {
        if (! $this->canSendOtp($email, $guard)) {
            throw new \Exception('Has alcanzado el límite de intentos. Intenta más tarde.');
        }

        // invalidar OTP anteriores
        DB::connection($this->connection)
            ->table('password_resets_otp')
            ->where('email', $email)
            ->where('guard', $guard)
            ->where('used', 0)
            ->update(['used' => 1]);

        $otp = random_int(100000, 999999);

        DB::connection($this->connection)
            ->table('password_resets_otp')
            ->insert([
                'email'      => $email,
                'guard'      => $guard,
                'otp_hash'   => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
            ]);

        return (string) $otp;
    }

    /* ===============================
       VALIDATE OTP
    =============================== */

    public function validateOtp(string $email, string $guard, string $otp): bool
    {
        $record = DB::connection($this->connection)
            ->table('password_resets_otp')
            ->where('email', $email)
            ->where('guard', $guard)
            ->where('used', 0)
            ->latest()
            ->first();

        if (! $record) {
            return false;
        }

        // 🔒 Verificar expiración
        if ($record->expires_at < now()) {
            return false;
        }

        // ⛔ Si ya tiene 5 intentos fallidos
        if ($record->attempts >= 5) {
            return false;
        }

        // ❌ OTP incorrecto
        if (! Hash::check($otp, $record->otp_hash)) {

            $newAttempts = $record->attempts + 1;

            DB::connection($this->connection)
                ->table('password_resets_otp')
                ->where('id', $record->id)
                ->update([
                    'attempts' => $newAttempts,
                ]);

            // 🚫 Si llega a 5 intentos, extender bloqueo 10 min
            if ($newAttempts >= 5) {
                DB::connection($this->connection)
                    ->table('password_resets_otp')
                    ->where('id', $record->id)
                    ->update([
                        'expires_at' => now()->addMinutes(10),
                    ]);
            }

            return false;
        }

        // ✅ OTP correcto
        DB::connection($this->connection)
            ->table('password_resets_otp')
            ->where('id', $record->id)
            ->update(['used' => 1]);

        return true;
    }

    /* ===============================
       UPDATE PASSWORD
    =============================== */

    public function updatePassword(string $email, string $guard, string $password): void
    {
        if ($guard === 'empleado') {

            DB::connection($this->connection)
                ->table('empleados')
                ->where('correo', $email)
                ->update([
                    'password'              => Hash::make($password),
                    'password_changed_at'   => now(),
                    'login_attempts'        => 0,
                    'locked_until'          => null,
                    'force_password_change' => 0,
                ]);
        }

        if ($guard === 'admin') {

            DB::connection($this->connection)
                ->table('datos_generales')
                ->where('correo', $email)
                ->update([
                    'password' => Hash::make($password),
                ]);
        }
    }
    protected function canSendOtp(string $email, string $guard): bool
    {
        $count = DB::connection($this->connection)
            ->table('password_resets_otp')
            ->where('email', $email)
            ->where('guard', $guard)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        return $count < 3;
    }

    /* ===============================
   VERIFY FLOW
================================ */

    public function verifyFlow($request, string $guard)
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|digits:6',
        ]);

        $valid = $this->validateOtp(
            $request->email,
            $guard,
            $request->otp
        );

        if (! $valid) {
            return response()->json([
                'status'  => false,
                'message' => 'Código inválido o expirado',
            ], 422);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Código verificado correctamente',
        ]);
    }

/* ===============================
   RESET FLOW
================================ */

    public function resetFlow($request, string $guard)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $this->updatePassword(
            $request->email,
            $guard,
            $request->password
        );

        return response()->json([
            'status'  => true,
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    public function getUserRecoveryData(string $email, string $guard): array
    {
        if ($guard === 'empleado') {

            $user = DB::connection($this->connection)
                ->table('empleados')
                ->where('correo', $email)
                ->where('status', 1)
                ->where('eliminado', 0)
                ->first();

            if (! $user) {
                return ['exists' => false];
            }

            $phone = $user->telefono;

        } else { // admin

            $user = DB::connection($this->connection)
                ->table('datos_generales')
                ->where('correo', $email)
                ->first();

            if (! $user) {
                return ['exists' => false];
            }

            $phone = $user->telefono;
        }

        if (! $phone) {
            return [
                'exists'    => true,
                'has_phone' => false,
            ];
        }

        return [
            'exists'      => true,
            'has_phone'   => true,
            'phone_last4' => substr($phone, -4),
        ];
    }
    public function verifyPhoneAndSendOtp(string $email, string $guard, string $inputPhone): void
    {
        if ($guard === 'empleado') {

            $user = DB::connection($this->connection)
                ->table('empleados')
                ->where('correo', $email)
                ->where('status', 1)
                ->where('eliminado', 0)
                ->first();

            if (! $user) {
                throw new \Exception('Usuario no encontrado');
            }

            $realPhone = $user->telefono;

        } else {

            $user = DB::connection($this->connection)
                ->table('datos_generales')
                ->where('correo', $email)
                ->first();

            if (! $user) {
                throw new \Exception('Usuario no encontrado');
            }

            $realPhone = $user->telefono;
        }

        if (! $realPhone) {
            throw new \Exception('No tienes teléfono registrado');
        }

        // Normalizar ambos por seguridad
        $normalizedInput = preg_replace('/\D/', '', $inputPhone);
        $normalizedReal  = preg_replace('/\D/', '', $realPhone);

        if ($normalizedInput !== $normalizedReal) {
            throw new \Exception('El número no coincide');
        }

        // Si coincide → generar OTP
        $otp = $this->generateOtp($email, $guard);

        // Enviar por WhatsApp
        app(\App\Modules\AuthCore\Services\OtpChannelService::class)
            ->sendByWhatsapp($normalizedReal, $otp);
    }


    public function getAdminWithPhone(string $email)
{
    return DB::connection($this->connection)
        ->table('datos_generales as dg')
        ->leftJoin('usuarios_portal as up', 'up.id_datos_generales', '=', 'dg.id')
        ->leftJoin('usuarios_clientes as uc', 'uc.id_datos_generales', '=', 'dg.id')
        ->where('dg.correo', $email)
        ->where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('up.status', 1)
                    ->where('up.eliminado', 0);
            })
            ->orWhere(function ($sub) {
                $sub->where('uc.status', 1)
                    ->where('uc.eliminado', 0);
            });
        })
        ->select('dg.telefono')
        ->first();
}

}
