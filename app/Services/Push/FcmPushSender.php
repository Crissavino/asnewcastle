<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1). Entrega push a Android e iOS (FCM hace
 * de proxy a APNs para iPhone). El envío usa OAuth2 con una service account:
 * firmamos un JWT con la clave privada y lo cambiamos por un access token.
 *
 * Sin dependencias de Composer: el JWT se firma con openssl (RS256).
 */
class FcmPushSender implements PushSender
{
    /** @var array{client_email: string, private_key: string, token_uri?: string} */
    private array $sa;

    public function __construct(string $credentials, private string $projectId)
    {
        // `credentials` puede ser el JSON de la service account o una ruta a él.
        $json = str_starts_with(trim($credentials), '{')
            ? $credentials
            : (is_file($credentials) ? (string) file_get_contents($credentials) : '');

        $sa = json_decode($json, true);

        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('FCM: service account inválida (client_email/private_key)');
        }

        $this->sa = $sa;
    }

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $invalid = [];

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('[push:fcm] no se pudo obtener access token: '.$e->getMessage());

            return []; // una tanda perdida no rompe nada
        }

        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId.'/messages:send';
        // FCM data solo acepta strings
        $data = array_map(fn ($v) => (string) $v, $data);

        foreach ($tokens as $token) {
            try {
                $res = Http::withToken($accessToken)->timeout(8)->post($url, [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => $data,
                    ],
                ]);
            } catch (\Throwable $e) {
                continue; // token roto o red: seguimos con los demás
            }

            // UNREGISTERED / INVALID_ARGUMENT → el token ya no sirve, a borrar
            if ($res->status() === 404 || $res->status() === 400) {
                $invalid[] = $token;
            }
        }

        return $invalid;
    }

    /** Access token OAuth2, cacheado ~50 min (dura 60). */
    private function accessToken(): string
    {
        return Cache::remember('fcm:access_token', 3000, function () {
            $now = now()->timestamp;
            $claims = [
                'iss' => $this->sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $this->sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = $this->signJwt($claims);

            $res = Http::asForm()->timeout(8)->post($this->sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $res->successful() || ! $res->json('access_token')) {
                throw new RuntimeException('OAuth2 falló: '.$res->status());
            }

            return $res->json('access_token');
        });
    }

    /** JWT RS256 firmado con la clave privada de la service account. */
    private function signJwt(array $claims): string
    {
        $b64 = fn ($data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode($claims));
        $signingInput = $header.'.'.$payload;

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $this->sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el JWT de FCM');
        }

        return $signingInput.'.'.$b64($signature);
    }
}
