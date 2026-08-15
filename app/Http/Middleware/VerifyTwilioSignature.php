<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Firma de Twilio: HMAC-SHA1 del URL completo más los parámetros POST
 * ordenados alfabéticamente, con el auth token como clave.
 * https://www.twilio.com/docs/usage/security#validating-requests
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.twilio.token');

        abort_if(empty($token), 403, 'Twilio no está configurado.');

        $params = $request->post();
        ksort($params);

        $payload = $request->fullUrl();
        foreach ($params as $key => $value) {
            $payload .= $key.$value;
        }

        $expected = base64_encode(hash_hmac('sha1', $payload, $token, true));

        abort_unless(
            hash_equals($expected, (string) $request->header('X-Twilio-Signature')),
            403,
            'Firma inválida.',
        );

        return $next($request);
    }
}
