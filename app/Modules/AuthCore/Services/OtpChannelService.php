<?php
namespace App\Modules\AuthCore\Services;

use Illuminate\Support\Facades\Http;

class OtpChannelService
{
    public function sendByWhatsapp(string $phone, string $otp): void
    {
        $token         = config('services.whatsapp.token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $baseUrl       = config('services.whatsapp.base_url');

        $response = Http::withToken($token)->post(
            "{$baseUrl}/{$phoneNumberId}/messages",
            [
                "messaging_product" => "whatsapp",
                "to"                => $phone,
                "type"              => "template",
                "template"          => [
                    "name"       => "otp_recovery_pass",
                    "language"   => [
                        "code" => "es_MX",
                    ],
                    "components" => [
                        [
                            "type"       => "body",
                            "parameters" => [
                                [
                                    "type" => "text",
                                    "text" => $otp,
                                ],
                            ],
                        ],
                        [
                            "type"       => "button",
                            "sub_type"   => "url", // 👈 ESTE ES EL CORRECTO
                            "index"      => "0",
                            "parameters" => [
                                [
                                    "type" => "text",
                                    "text" => $otp,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        if (! $response->successful()) {
            \Log::error('WhatsApp OTP error', [
                'response' => $response->body(),
            ]);
            throw new \Exception('Error enviando WhatsApp');
        }
    }
}
