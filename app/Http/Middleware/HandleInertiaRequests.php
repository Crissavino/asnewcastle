<?php

namespace App\Http\Middleware;

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
                'role' => $current->member()->role,
                'shirt_number' => $current->member()->shirt_number,
                'position' => $current->member()->position,
            ] : null,

            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'invite_url' => fn () => $request->session()->get('invite_url'),
            ],
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
