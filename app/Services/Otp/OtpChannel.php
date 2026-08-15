<?php

namespace App\Services\Otp;

interface OtpChannel
{
    public function sendCode(string $phone, string $code): void;
}
