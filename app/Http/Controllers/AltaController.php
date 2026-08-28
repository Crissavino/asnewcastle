<?php

namespace App\Http\Controllers;

use App\Support\CurrentClub;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AltaController extends Controller
{
    public const POSITIONS = ['ARQ', 'DEF', 'MED', 'DEL'];

    public const FEET = ['right', 'left', 'both'];

    /** Días de semana = entrenamiento (después de las 19/20); finde = partido. */
    public const SLOTS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const MAX_NUMBER = 30;

    public function show(): Response|RedirectResponse
    {
        $current = app(CurrentClub::class);

        if ($current->member()->profileComplete()) {
            return redirect()->route('agenda');
        }

        return Inertia::render('Alta', [
            'taken' => $this->takenNumbers(),
            'positions' => self::POSITIONS,
            'feet' => self::FEET,
            'slots' => self::SLOTS,
            'max_number' => self::MAX_NUMBER,
            'first_name' => $current->member()->user->firstName(),
            'last_name' => $current->member()->user->lastName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $current = app(CurrentClub::class);
        $member = $current->member();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:40'],
            'last_name' => ['required', 'string', 'min:2', 'max:40'],
            'position' => ['required', Rule::in(self::POSITIONS)],
            'preferred_foot' => ['required', Rule::in(self::FEET)],
            'shirt_number' => [
                'required', 'integer', 'min:1', 'max:'.self::MAX_NUMBER,
                Rule::notIn($this->takenNumbers()),
            ],
            'availability' => ['required', 'array', 'min:1'],
            'availability.*' => [Rule::in(self::SLOTS)],
        ], [
            'shirt_number.not_in' => __('alta.number_taken'),
        ]);

        try {
            DB::transaction(function () use ($validated, $member) {
                // Nombre canónico "Nombre Apellido" con cada palabra en
                // mayúscula (incluye apellidos con guion, típico rumano).
                $member->user->update([
                    'name' => \App\Models\User::properCase($validated['first_name'].' '.$validated['last_name']),
                ]);
                $member->update([
                    'position' => $validated['position'],
                    'preferred_foot' => $validated['preferred_foot'],
                    'shirt_number' => $validated['shirt_number'],
                    'availability' => array_values($validated['availability']),
                ]);
            });
        } catch (QueryException $e) {
            // Dos requests pasaron la validación a la vez: el unique(club_id,
            // shirt_number) corta la segunda y se lo mostramos como error normal.
            if ($e->errorInfo[0] === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
                throw ValidationException::withMessages([
                    'shirt_number' => __('alta.number_taken'),
                ]);
            }

            throw $e;
        }

        return redirect()->route('agenda');
    }

    /** Dorsales ocupados en el club activo, sin contar el propio. */
    protected function takenNumbers(): array
    {
        $current = app(CurrentClub::class);

        return $current->club()->activeMembers()
            ->whereNotNull('shirt_number')
            ->whereKeyNot($current->member()->id)
            ->pluck('shirt_number')
            ->all();
    }
}
