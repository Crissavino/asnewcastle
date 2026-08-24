<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Registration;
use App\Services\RegistrationSaver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulario público de legitimación: para jugadores nuevos que todavía
 * no tienen cuenta (sin WhatsApp activo no pueden pasar el OTP). Se entra
 * solo por el link firmado que genera el manager. La sesión ata la ficha
 * al navegador para que el guardado parcial funcione sin login, y NO da
 * acceso a nada más de la app.
 */
class PublicRegistrationController extends Controller
{
    public function __construct(protected RegistrationSaver $saver)
    {
    }

    /** El link firmado abre el formulario y deja el club en sesión. */
    public function show(Request $request, Club $club): Response|RedirectResponse
    {
        // Un member logueado del club no llena una ficha de invitado (sería
        // un duplicado): va derecho a la suya. Así el mismo link sirve para
        // todo el grupo, tenga cuenta o no.
        if ($request->user()?->activeMembers()->where('club_id', $club->id)->exists()) {
            return redirect()->route('legitimacion');
        }

        $request->session()->put('legitimacion_publica_club_id', $club->id);

        $reg = $this->sessionRegistration($request, $club->id);

        return Inertia::render('LegitimacionPublica', [
            'club' => ['name' => $club->name, 'crest' => $club->crest_path ? asset($club->crest_path) : null],
            'registration' => $reg
                ? $this->saver->serialize($reg)
                : $this->saver->serialize(new Registration(['club_id' => $club->id])),
            'missing' => $reg?->missingFields(),
            'config' => $this->saver->config(),
        ]);
    }

    /** Guardado parcial del invitado: la fila se crea en el primer POST. */
    public function store(Request $request): RedirectResponse
    {
        $clubId = $request->session()->get('legitimacion_publica_club_id');
        abort_unless($clubId && Club::whereKey($clubId)->exists(), 403);

        $reg = $this->sessionRegistration($request, $clubId) ?? new Registration([
            'club_id' => $clubId,
            'member_id' => null,
            'season' => config('legitimacion.season'),
        ]);

        $this->saver->save($reg, $request);

        $request->session()->put('legitimacion_publica_registration_id', $reg->id);

        return back();
    }

    /** La ficha de ESTE navegador: id en sesión, siempre validada por club. */
    protected function sessionRegistration(Request $request, int $clubId): ?Registration
    {
        $id = $request->session()->get('legitimacion_publica_registration_id');

        if (! $id) {
            return null;
        }

        return Registration::withoutGlobalScopes()
            ->whereKey($id)
            ->where('club_id', $clubId)
            ->whereNull('member_id')
            ->first();
    }
}
