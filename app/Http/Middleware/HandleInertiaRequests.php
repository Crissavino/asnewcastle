<?php

namespace App\Http\Middleware;

use App\Models\Due;
use App\Models\DuePromise;
use App\Models\Event;
use App\Models\Notification;
use App\Support\CurrentClub;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $current = app(CurrentClub::class);
        $locale = app()->getLocale();

        return [
            ...parent::share($request),

            'locale' => $locale,
            // Ojo con el nombre: tiene que ser único para que ninguna página
            // lo pise con un prop propio (el chat usa "messages", por ejemplo).
            'translations' => fn () => $this->translations($locale),

            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'locale' => $request->user()->locale,
                ] : null,
            ],

            'club' => fn () => $current->club() ? [
                'id' => $current->club()->id,
                'name' => $current->club()->name,
                'city' => $current->club()->city,
                'league' => $current->club()->league,
                'crest' => $current->club()->crest_path ? asset($current->club()->crest_path) : null,
            ] : null,

            'member' => fn () => $current->member() ? [
                'id' => $current->member()->id,
                // Rol EFECTIVO: si el dueño está "viendo como jugador", figura player.
                'role' => $current->effectiveRole(),
                'shirt_number' => $current->member()->shirt_number,
                'position' => $current->member()->position,
            ] : null,

            // La campanita: contador de no-leídas + las últimas para el panel.
            'notifications' => fn () => $current->member() ? $this->notifications($current) : null,

            // Deuda de cuota del jugador activo (null si está al día): alimenta
            // el popup de cobranza y el banner de tregua en Cuota.
            'debt' => fn () => $current->member() ? $this->debt($current) : null,

            // Solo el dueño ve el toggle admin/jugador, y en qué modo está.
            'is_owner' => fn () => $current->isOwner(),
            'viewing_as_player' => fn () => $current->viewingAsPlayer(),

            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'invite_url' => fn () => $request->session()->get('invite_url'),
                'reminded' => fn () => $request->session()->get('reminded'),
            ],

            // Control de versión del APK: la app nativa Android compara su
            // versionCode con éste y, si quedó atrás, propone bajar el APK nuevo
            // (el sideload no se auto-actualiza como Play/TestFlight).
            'android_update' => [
                'latest_code' => (int) config('onboarding.apk_version_code'),
                'apk_url' => config('onboarding.apk_url'),
            ],
        ];
    }

    /** Campanita del jugador activo: no-leídas + las últimas 15 para el panel. */
    protected function notifications(CurrentClub $current): array
    {
        $memberId = $current->member()->id;

        return [
            'unread' => Notification::where('member_id', $memberId)->whereNull('read_at')->count(),
            'items' => Notification::where('member_id', $memberId)
                ->latest()
                ->limit(15)
                ->get()
                ->map(fn (Notification $n) => [
                    'id' => $n->id,
                    'key' => $n->body_key,
                    'params' => $n->body_params,
                    'url' => $n->url,
                    'read' => $n->read_at !== null,
                    'at' => $n->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Deuda acumulada del jugador y estado de su compromiso de pago.
     * Null si está al día — el popup ni aparece. El estado de las promesas
     * vencidas se resuelve acá (settle perezoso, sin cron).
     */
    protected function debt(CurrentClub $current): ?array
    {
        $member = $current->member();

        $pending = Due::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->get(['amount_cents']);

        if ($pending->isEmpty()) {
            return null;
        }

        DuePromise::settle($member->id);

        // La última promesa que importa: vigente (tregua) o rota (reproche).
        // Las cumplidas y reemplazadas no se muestran.
        $promise = DuePromise::query()
            ->where('member_id', $member->id)
            ->where('kind', DuePromise::KIND_PROMISE)
            ->whereIn('status', ['active', 'broken'])
            ->orderByDesc('id')
            ->first();

        $active = $promise?->status === 'active';

        return [
            'total_cents' => (int) $pending->sum('amount_cents'),
            'months' => $pending->count(),
            'currency' => $current->club()->currency,
            // Para la opción rápida "el día del partido" al elegir fecha
            'next_match_on' => Event::query()
                ->where('kind', 'match')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->value('starts_at')?->toDateString(),
            'promise' => $promise ? [
                'promised_for' => $promise->promised_for->toDateString(),
                'active' => $active,
                'broken' => $promise->status === 'broken',
            ] : null,
            'show_popup' => ! $active && ! $member->due_popup_seen_on?->isToday(),
        ];
    }

    /** Diccionario completo del locale activo, para el helper t() de React. */
    protected function translations(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            $path = lang_path(config('app.fallback_locale').'.json');
        }

        return is_file($path) ? json_decode(file_get_contents($path), true) : [];
    }
}
