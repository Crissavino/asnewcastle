<?php

namespace Tests\Support;

use App\Services\Otp\OtpChannel;

/** Canal falso para tests: guarda los códigos en vez de mandarlos. */
class FakeOtpChannel implements OtpChannel
{
    /** @var array<int, array{phone: string, code: string}> */
    public array $sent = [];

    public function sendCode(string $phone, string $code): void
    {
        $this->sent[] = ['phone' => $phone, 'code' => $code];
    }

    public function lastCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($message['phone'] === $phone) {
                return $message['code'];
            }
        }

        return null;
    }
}
