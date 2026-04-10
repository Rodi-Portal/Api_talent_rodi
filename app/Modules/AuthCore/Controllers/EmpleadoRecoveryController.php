<?php
namespace App\Modules\AuthCore\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AuthCore\Mail\PasswordRecoveryMail;
use App\Modules\AuthCore\Services\PasswordRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmpleadoRecoveryController extends Controller
{
    private string $guard = 'empleado';

    public function sendOtp(Request $request, PasswordRecoveryService $service)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        if (! $service->userExists($request->email, $this->guard)) {
            return response()->json([
                'status'  => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        // Genera OTP
        $otp = $service->generateOtp($request->email, $this->guard);

        try {
            // 👇 IMPORTANTE: usar mailer auth_smtp
            Mail::mailer('auth_smtp')
                ->to($request->email)
                ->send(new PasswordRecoveryMail($otp));

        } catch (\Exception $e) {

            \Log::error('Error enviando OTP', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error enviando correo',
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Código enviado',
        ]);
    }

    public function verifyOtp(Request $request, PasswordRecoveryService $service)
    {
        return $service->verifyFlow($request, $this->guard);
    }

    public function resetPassword(Request $request, PasswordRecoveryService $service)
    {
        return $service->resetFlow($request, $this->guard);
    }

    public function checkUser(Request $request, PasswordRecoveryService $service)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $data = $service->getUserRecoveryData($request->email, $this->guard);

        if (! $data['exists']) {
            return response()->json([
                'status'  => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        return response()->json([
            'status'      => true,
            'has_phone'   => $data['has_phone'] ?? false,
            'phone_last4' => $data['phone_last4'] ?? null,
        ]);
    }
    public function verifyPhone(Request $request, PasswordRecoveryService $service)
    {
        $request->validate([
            'email'           => 'required|email',
            'country_code'    => 'required',
            'national_number' => 'required',
            'full_phone'      => 'required',
        ]);

        $email    = $request->email;
        $national = preg_replace('/\D/', '', $request->national_number);
        $country  = preg_replace('/\D/', '', $request->country_code);
        $full     = ltrim($request->full_phone, '+');

        // 🔎 Buscar teléfono en BD
        $storedPhone = \DB::connection('portal_main')
            ->table('empleados')
            ->where('correo', $email)
            ->where('status', 1)
            ->where('eliminado', 0)
            ->value('telefono');

        if (! $storedPhone) {
            return response()->json([
                'status'  => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        $storedPhone = preg_replace('/\D/', '', $storedPhone);

        // ❌ Si no coincide
        if ($storedPhone !== $national) {
            return response()->json([
                'status'  => false,
                'message' => 'El número no coincide con el registrado',
            ], 422);
        }

        // ✅ Generar OTP
        $otp = $service->generateOtp($email, 'empleado');

        // 🚀 Enviar por WhatsApp
        app(\App\Modules\AuthCore\Services\OtpChannelService::class)
            ->sendByWhatsapp($full, $otp);

        return response()->json([
            'status'  => true,
            'message' => 'Código enviado por WhatsApp',
        ]);
    }
}
