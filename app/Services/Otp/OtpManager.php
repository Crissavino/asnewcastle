<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class OtpManager
{
    /** Validez del código en segundos (10 minutos). */
    public const TTL = 600;

    /** Máximo de códigos por número por hora. */
    public const MAX_SENDS_PER_HOUR = 3;

    /** Intentos de verificación por código antes de invalidarlo. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(protected OtpChannel $channel)
    {
    }

    /**
     * Genera y manda un código. Devuelve false si el número superó
     * el límite de envíos por hora.
     */
    public function send(string $phone): bool
    {
        $allowed = RateLimiter::attempt(
            'otp-send:'.$phone,
            self::MAX_SENDS_PER_HOUR,
            fn () => true,
            3600,
        );

        if (! $allowed) {
            return false;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($phone), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], self::TTL);

        $this->channel->sendCode($phone, $code);

        return true;
    }

    /**
     * Verifica el código. Un solo uso: si es correcto se borra.
     * Después de MAX_ATTEMPTS intentos fallidos también se borra.
     */
    public function verify(string $phone, string $code): bool
    {
        if ($this->isMasterCode($code)) {
            Cache::forget($this->key($phone));

            return true;
        }

        $key = $this->key($phone);
        $entry = Cache::get($key);

        if ($entry === null) {
            return false;
        }

        if (! Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;

            if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
            } else {
                Cache::put($key, $entry, self::TTL);
            }

            return false;
        }

        Cache::forget($key);

        return true;
    }

    public function secondsUntilNextSend(string $phone): int
    {
        return RateLimiter::availableIn('otp-send:'.$phone);
    }

    /**
     * ¿Hay código maestro configurado? Mientras Twilio no manda los OTP, el
     * login se hace solo con este código, así que no hace falta "enviar" nada
     * ni frenar por el rate limit de envíos.
     */
    public function hasMasterCode(): bool
    {
        return strlen((string) config('services.otp.master_code', '')) >= 7;
    }

    protected function key(string $phone): string
    {
        return 'otp:'.$phone;
    }

    /**
     * Código maestro temporal (mientras Twilio no manda los OTP).
     * Solo existe si OTP_MASTER_CODE está en el .env, y se exige largo 7+
     * para que jamás colisione con un código real de 6 dígitos.
     */
    protected function isMasterCode(string $code): bool
    {
        $master = (string) config('services.otp.master_code', '');

        return strlen($master) >= 7 && hash_equals($master, $code);
    }
}
