<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Azure Translator (capa gratuita F0: 2M caracteres/mes). Una sola llamada
 * devuelve la traducción y el idioma de origen detectado.
 * Docs: https://learn.microsoft.com/azure/ai-services/translator/
 */
class AzureTranslator implements Translator
{
    public function __construct(
        private string $key,
        private string $region,
        private string $endpoint,
    ) {
    }

    public function translate(string $text, string $to): array
    {
        try {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->key,
                'Ocp-Apim-Subscription-Region' => $this->region,
                'Content-Type' => 'application/json',
            ])
                ->timeout(8)
                ->post(rtrim($this->endpoint, '/').'/translate?api-version=3.0&to='.$to, [
                    ['Text' => $text],
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Azure Translator no respondió', 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Azure Translator devolvió '.$response->status());
        }

        $item = $response->json('0');

        return [
            'text' => $item['translations'][0]['text'] ?? $text,
            'from' => $item['detectedLanguage']['language'] ?? null,
        ];
    }
}
