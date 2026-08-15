<?php

namespace Tests\Support;

use App\Services\WhatsApp\WhatsAppChannel;

/** Canal falso: acumula los envíos para inspeccionarlos en los tests. */
class FakeWhatsAppChannel implements WhatsAppChannel
{
    /** @var array<int, array{to: string, contentSid: string, variables: array}> */
    public array $templates = [];

    /** @var array<int, array{to: string, body: string}> */
    public array $texts = [];

    public function sendTemplate(string $to, string $contentSid, array $variables): void
    {
        $this->templates[] = ['to' => $to, 'contentSid' => $contentSid, 'variables' => $variables];
    }

    public function sendText(string $to, string $body): void
    {
        $this->texts[] = ['to' => $to, 'body' => $body];
    }

    public function templateRecipients(): array
    {
        return array_column($this->templates, 'to');
    }
}
