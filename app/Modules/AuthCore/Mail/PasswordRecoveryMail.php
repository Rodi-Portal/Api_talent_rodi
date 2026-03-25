<?php
namespace App\Modules\AuthCore\Mail;

use Illuminate\Mail\Mailable;

class PasswordRecoveryMail extends Mailable
{
    public string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function build()
    {
        return $this
            ->from(
                env('AUTH_MAIL_USERNAME'),
                'TalentSafe Seguridad'
            )
            ->subject('Código de recuperación')
            ->view('authcore.emails.password_recovery');
    }
}
