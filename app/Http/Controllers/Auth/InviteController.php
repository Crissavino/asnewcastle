<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class InviteController extends Controller
{
    /**
     * El manager genera un link firmado que vence a los 30 días. Es un link del
     * club (no atado a un número): sirve para todo el plantel, se pega una vez en
     * el grupo y cada jugador entra con su número. Es la única puerta de entrada
     * al club: no hay registro abierto.
     */
    public function create(Request $request): RedirectResponse
    {
        $current = app(CurrentClub::class);

        abort_unless($current->member()?->isManager(), 403);

        $url = URL::temporarySignedRoute('invitacion', now()->addDays(30), [
            'club' => $current->club()->slug,
        ]);

        return back()->with('invite_url', $url);
    }

    /**
     * El invitado abre el link. Si ya está logueado, entra directo al club. Si no,
     * se recuerda el club en la sesión y se le muestra una guía de descarga (según
     * Android/iPhone) con el paso final de login; el botón "entrar" lo manda al OTP,
     * donde queda asociado al club.
     */
    public function accept(Request $request, Club $club): RedirectResponse|Response
    {
        if ($user = $request->user()) {
            $this->joinOrRejoin($user, $club->id);

            return redirect()->route('agenda');
        }

        $request->session()->put('invite_club_id', $club->id);

        return Inertia::render('Auth/Sumate', [
            'clubName' => $club->name,
            // Interino sin WhatsApp: se muestra el código maestro. Cuando Twilio
            // esté vivo, OTP_MASTER_CODE se borra y la guía dice "te llega por WhatsApp".
            'masterCode' => config('services.otp.master_code') ?: null,
            'apkUrl' => config('onboarding.apk_url'),
            'testflightUrl' => config('onboarding.testflight_url'),
            'testflightAppStoreUrl' => config('onboarding.testflight_appstore_url'),
        ]);
    }

    /** Si es un ex-member que vuelve, se le levanta la baja; si no, se crea. */
    public static function joinOrRejoin($user, int $clubId): void
    {
        $member = $user->members()->firstOrNew(['club_id' => $clubId]);

        if (! $member->exists) {
            $member->fill(['role' => 'player', 'joined_at' => now()]);
        }

        $member->left_at = null;
        $member->save();
    }
}
