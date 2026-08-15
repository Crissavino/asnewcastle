<?php

namespace App\Services\Otp;

use Twilio\Rest\Client;

/**
 * Manda el código por WhatsApp usando una plantilla de Twilio de
 * categoría "authentication" (Content API). La plantilla tiene una
 * sola variable: el código.
 */
class TwilioOtpChannel implements OtpChannel
{
    public function __construct(protected Client $client)
    {
    }

    public function sendCode(string $phone, string $code): void
    {
        $this->client->messages->create('whatsapp:'.$phone, [
            'from' => 'whatsapp:'.config('services.twilio.whatsapp_from'),
            'contentSid' => config('services.twilio.otp_template_sid'),
            'contentVariables' => json_encode(['1' => $code]),
        ]);
    }
}
