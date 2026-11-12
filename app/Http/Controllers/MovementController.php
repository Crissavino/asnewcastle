<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Movement;
use App\Services\Ledger;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Movimientos del libro que no son gastos ni cuotas. Un método por acción.
 */
class MovementController extends Controller
{
    /**
     * Mover plata entre bolsillos. NO es un gasto: el total del club no cambia.
     * Escribe dos filas opuestas atadas por group_uuid y no crea ningún Expense;
     * el gasto nace después, cuando se paga lo que sea.
     */
    public function transfer(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $request->validate([
            'from' => ['required', Rule::in(Movement::POTS)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            'occurred_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $to = $validated['from'] === 'cuenta' ? 'cash' : 'cuenta';
        $member = app(CurrentClub::class)->member();
        $group = (string) Str::uuid();

        DB::transaction(function () use ($validated, $to, $member, $group) {
            foreach ([[$validated['from'], -1], [$to, 1]] as [$pot, $sign]) {
                Movement::create([
                    'pot' => $pot,
                    'kind' => 'transferencia',
                    'amount_cents' => $sign * $validated['amount_cents'],
                    'occurred_on' => $validated['occurred_on'],
                    'group_uuid' => $group,
                    'note' => $validated['note'] ?? null,
                    'created_by_member_id' => $member->id,
                ]);
            }
        });

        // Avisa, nunca frena: se mueve plata que todavía no entró.
        $redirect = back();
        if (app(Ledger::class)->balance($validated['from']) < 0) {
            $redirect->with('aviso_saldo_negativo', true);
        }

        return $redirect;
    }
}
