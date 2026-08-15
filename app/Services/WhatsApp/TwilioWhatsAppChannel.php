<?php

namespace App\Services\WhatsApp;

use Twilio\Rest\Client;

class TwilioWhatsAppChannel implements WhatsAppChannel
{
    public function __construct(protected Client $client)
    {
    }

    public function sendTemplate(string $to, string $contentSid, array $variables): void
    {
        $this->client->messages->create('whatsapp:'.$to, [
            'from' => 'whatsapp:'.config('services.twilio.whatsapp_from'),
            'contentSid' => $contentSid,
            'contentVariables' => json_encode($variables),
        ]);
    }

    public function sendText(string $to, string $body): void
    {
        $this->client->messages->create('whatsapp:'.$to, [
            'from' => 'whatsapp:'.config('services.twilio.whatsapp_from'),
            'body' => $body,
        ]);
    }
}
