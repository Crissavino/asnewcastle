<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\Translation\Translator;
use App\Support\CurrentClub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MessageTranslationController extends Controller
{
    /** Máximo de traducciones por usuario por hora. */
    public const MAX_PER_HOUR = 30;

    /**
     * Traduce un mensaje de un jugador al idioma del que lee. Consulta SIEMPRE
     * el caché primero: un mensaje se traduce una vez por idioma y nunca más.
     * Nunca rompe el chat: si la API falla o se acabó la cuota, devuelve ok=false.
     */
    public function translate(Request $request, Message $message, Translator $translator): JsonResponse
    {
        $current = app(CurrentClub::class);
        $current->assertOwns($message);

        abort_if($message->is_system || trim((string) $message->body) === '', 400);

        $to = $request->user()->locale ?: 'es';

        // 1) Caché: si ya se tradujo a este idioma, se devuelve sin tocar la API.
        $cached = $message->translations()->where('locale', $to)->first();

        if ($cached) {
            return response()->json(['ok' => true, 'text' => $cached->body]);
        }

        // 2) Rate limit por usuario (solo cuenta cuando de verdad hay que llamar)
        $key = 'translate:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            return response()->json(['ok' => false], 200);
        }

        RateLimiter::hit($key, 3600);

        // 3) API. Si falla o se acabó la cuota, no rompemos el chat.
        try {
            $result = $translator->translate($message->body, $to);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false], 200);
        }

        // Guardamos el idioma detectado (una sola vez) y cacheamos la traducción.
        if (! $message->detected_locale && ! empty($result['from'])) {
            $message->update(['detected_locale' => substr($result['from'], 0, 2)]);
        }

        $message->translations()->create([
            'locale' => $to,
            'body' => $result['text'],
        ]);

        return response()->json(['ok' => true, 'text' => $result['text']]);
    }
}
