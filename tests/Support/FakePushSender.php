<?php

namespace Tests\Support;

use App\Services\Push\PushSender;

class FakePushSender implements PushSender
{
    /** @var array<int, array{tokens: string[], title: string, body: string, data: array}> */
    public array $sent = [];

    /** Tokens que este fake reportará como inválidos. */
    public array $invalid = [];

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $this->sent[] = compact('tokens', 'title', 'body', 'data');

        return array_values(array_intersect($tokens, $this->invalid));
    }

    /** Todos los tokens de todas las tandas. */
    public function allTokens(): array
    {
        return collect($this->sent)->flatMap(fn ($s) => $s['tokens'])->all();
    }
}
