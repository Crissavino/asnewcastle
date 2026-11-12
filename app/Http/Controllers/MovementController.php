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
        $group = (string) Str::uuid();

        DB::transaction(function () use ($validated, $to, $group) {
            foreach ([[$validated['from'], -1], [$to, 1]] as [$pot, $sign]) {
                $this->record($pot, 'transferencia', $sign * $validated['amount_cents'], $validated['occurred_on'], $validated['note'] ?? null, $group);
            }
        });

        return $this->backWarningIfNegative($validated['from']);
    }

    /**
     * Plata que entra y no es cuota (patrimonio inicial, rifa, donación,
     * devolución). La nota es obligatoria: un movimiento sin explicación
     * vuelve inauditable el libro.
     */
    public function ingreso(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $request->validate([
            'pot' => ['required', Rule::in(Movement::POTS)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            'occurred_on' => ['required', 'date'],
            'note' => ['required', 'string', 'max:120'],
        ]);

        $this->record($validated['pot'], 'ingreso', $validated['amount_cents'], $validated['occurred_on'], $validated['note']);

        return back();
    }

    /**
     * Ajuste contra el extracto. El usuario dicta el saldo OBJETIVO ("el banco
     * dice 180"); lo que se guarda es objetivo - actual, con signo. Diferencia
     * cero no crea fila: sería ruido en el historial.
     */
    public function ajuste(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $request->validate([
            'pot' => ['required', Rule::in(Movement::POTS)],
            'target_cents' => ['required', 'integer', 'min:-100000000', 'max:100000000'],
            'note' => ['required', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($validated) {
            $difference = $validated['target_cents'] - app(Ledger::class)->balance($validated['pot']);

            if ($difference !== 0) {
                $this->record($validated['pot'], 'ajuste', $difference, now()->toDateString(), $validated['note']);
            }
        });

        // El objetivo lo dicta el banco: si hay menos, hay menos. Avisa, no frena.
        return $this->backWarningIfNegative($validated['pot']);
    }

    private function record(string $pot, string $kind, int $amountCents, string $occurredOn, ?string $note, ?string $group = null): void
    {
        Movement::create([
            'pot' => $pot,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'occurred_on' => $occurredOn,
            'group_uuid' => $group,
            'note' => $note,
            'created_by_member_id' => app(CurrentClub::class)->member()->id,
        ]);
    }

    /** Avisa, nunca frena: el saldo puede quedar en negativo. */
    private function backWarningIfNegative(string $pot): RedirectResponse
    {
        $redirect = back();
        if (app(Ledger::class)->balance($pot) < 0) {
            $redirect->with('aviso_saldo_negativo', true);
        }

        return $redirect;
    }
}
