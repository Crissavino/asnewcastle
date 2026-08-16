<?php

namespace App\Http\Controllers;

use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PerfilController extends Controller
{
    public function show(): Response
    {
        $current = app(CurrentClub::class);
        $member = $current->member();

        // El plantel: dorsal, nombre y puesto. Nunca teléfonos.
        $roster = $current->club()->activeMembers()
            ->with('user:id,name')
            ->orderBy('shirt_number')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->user->name,
                'shirt_number' => $m->shirt_number,
                'position' => $m->position,
            ]);

        return Inertia::render('Perfil', [
            'me' => [
                'name' => $member->user->name,
                'shirt_number' => $member->shirt_number,
                'position' => $member->position,
                'preferred_foot' => $member->preferred_foot,
                'availability' => $member->availability ?? [],
            ],
            'slots' => AltaController::SLOTS,
            'roster' => $roster,
        ]);
    }

    /** La disponibilidad se edita desde el perfil, sin repetir el wizard. */
    public function updateAvailability(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'availability' => ['required', 'array', 'min:1'],
            'availability.*' => [Rule::in(AltaController::SLOTS)],
        ]);

        app(CurrentClub::class)->member()->update([
            'availability' => array_values(array_unique($validated['availability'])),
        ]);

        return back();
    }
}
