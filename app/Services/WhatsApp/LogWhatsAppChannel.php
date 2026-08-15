<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

class LogWhatsAppChannel implements WhatsAppChannel
{
    public function sendTemplate(string $to, string $contentSid, array $variables): void
    {
        Log::info("WhatsApp (plantilla {$contentSid}) a {$to}: ".json_encode($variables, JSON_UNESCAPED_UNICODE));
    }

    public function sendText(string $to, string $body): void
    {
        Log::info("WhatsApp (texto) a {$to}: {$body}");
    }
}
