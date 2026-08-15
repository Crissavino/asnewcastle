<?php

namespace App\Services\WhatsApp;

interface WhatsAppChannel
{
    /** Manda una plantilla aprobada (Content API). $variables con índices "1", "2", ... */
    public function sendTemplate(string $to, string $contentSid, array $variables): void;

    /** Texto libre: solo válido dentro de la ventana de 24hs de una conversación. */
    public function sendText(string $to, string $body): void;
}
