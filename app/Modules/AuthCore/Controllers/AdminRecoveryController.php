<?php

namespace App\Modules\AuthCore\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

use App\Modules\AuthCore\Services\PasswordRecoveryService;
use App\Modules\AuthCore\Services\OtpChannelService;
use App\Modules\AuthCore\Mail\PasswordRecoveryMail;

class AdminRecoveryController extends Controller
{
    private string $guard = 'admin';

    /* =====================================================
       STEP 1 — CHECK USER
    ===================================================== */

    public function checkUser(Request $request, PasswordRecoveryService $service)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = $service->getAdminWithPhone($request->email);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        return response()->json([
            'status'      => true,
            'has_phone'   => !empty($user->telefono),
            'phone_last4' => $user->telefono
                ? substr(preg_replace('/\D/', '', $user->telefono), -4)
                : null,
        ]);
    }

    /* =====================================================
       STEP 2 — SEND EMAIL OTP
    ===================================================== */

    public function sendOtp(Request $request, PasswordRecoveryService $service)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        if (!$service->userExists($request->email, $this->guard)) {
            return response()->json([
                'status' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $otp = $service->generateOtp($request->email, $this->guard);

        try {
            Mail::mailer('auth_smtp')
                ->to($request->email)
                ->send(new PasswordRecoveryMail($otp));

        } catch (\Exception $e) {

            Log::error('Error enviando OTP ADMIN EMAIL', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error enviando correo'
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'Código enviado por correo'
        ]);
    }

    /* =====================================================
       STEP 3 — VERIFY PHONE + SEND WHATSAPP OTP
    ===================================================== */

    public function verifyPhone(
        Request $request,
        PasswordRecoveryService $service,
        OtpChannelService $otpChannel
    ) {
        $request->validate([
            'email'           => 'required|email',
            'country_code'    => 'required',
            'national_number' => 'required',
            'full_phone'      => 'required'
        ]);

        $user = $service->getAdminWithPhone($request->email);

        if (!$user || !$user->telefono) {
            return response()->json([
                'status' => false,
                'message' => 'Usuario sin teléfono registrado'
            ], 400);
        }

        // Normalizar
        $dbPhone  = preg_replace('/\D/', '', $user->telefono);
        $inputPhone = preg_replace('/\D/', '', $request->country_code . $request->national_number);

        if ($dbPhone !== $request->national_number) {
            return response()->json([
                'status' => false,
                'message' => 'El número no coincide con el registrado'
            ], 400);
        }

        $otp = $service->generateOtp($request->email, $this->guard);

        $otpChannel->sendWhatsAppOtp($request->full_phone, $otp);

        return response()->json([
            'status' => true,
            'message' => 'Código enviado por WhatsApp'
        ]);
    }

    /* =====================================================
       STEP 4 — VERIFY OTP
    ===================================================== */

    public function verifyOtp(Request $request, PasswordRecoveryService $service)
    {
        return $service->verifyFlow($request, $this->guard);
    }

    /* =====================================================
       STEP 5 — RESET PASSWORD
    ===================================================== */

    public function resetPassword(Request $request, PasswordRecoveryService $service)
    {
        return $service->resetFlow($request, $this->guard);
    }
}