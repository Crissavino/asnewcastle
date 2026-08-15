<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Canal de desarrollo: escribe el código en el log en vez de gastar
 * mensajes de Twilio. Se activa con OTP_CHANNEL=log.
 */
class LogOtpChannel implements OtpChannel
{
    public function sendCode(string $phone, string $code): void
    {
        Log::info("OTP para {$phone}: {$code}");
    }
}
