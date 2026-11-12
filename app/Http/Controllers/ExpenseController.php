<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Expense;
use App\Models\Movement;
use App\Services\Ledger;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $validated = $request->validate([
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            // Se permite fecha futura: a veces se registra un gasto para un
            // evento que todavía no pasó (ej. pago de la cancha por adelantado).
            'spent_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:120'],
            'event_id' => ['nullable', 'integer'],
            'pot' => ['required', Rule::in(Movement::POTS)],
        ]);

        // El evento atado tiene que ser del club (el scope global ya filtra)
        if (! empty($validated['event_id']) && ! Event::whereKey($validated['event_id'])->exists()) {
            abort(404);
        }

        $member = app(CurrentClub::class)->member();

        $expense = DB::transaction(function () use ($validated, $member) {
            $expense = Expense::create([...$validated, 'member_id' => $member->id]);

            Movement::create([
                'pot' => $expense->pot,
                'kind' => 'gasto',
                'amount_cents' => -$expense->amount_cents,
                'occurred_on' => $expense->spent_on,
                'expense_id' => $expense->id,
                'created_by_member_id' => $member->id,
            ]);

            return $expense;
        });

        // Avisa, nunca frena: el delegado puede pagar antes de que entre la plata.
        $redirect = back();
        if (app(Ledger::class)->balance($expense->pot) < 0) {
            $redirect->with('aviso_saldo_negativo', true);
        }

        return $redirect;
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        Gate::authorize('create', Event::class);
        app(CurrentClub::class)->assertOwns($expense);

        DB::transaction(function () use ($expense) {
            // Contra-asiento: el movimiento original queda; se anula con el inverso.
            $original = Movement::where('expense_id', $expense->id)
                ->whereNull('reverses_movement_id')
                ->first();

            if ($original) {
                Movement::create([
                    'pot' => $original->pot,
                    'kind' => 'gasto',
                    'amount_cents' => -$original->amount_cents,
                    'occurred_on' => now()->toDateString(),
                    'reverses_movement_id' => $original->id,
                    'expense_id' => $expense->id,
                    'created_by_member_id' => app(CurrentClub::class)->member()->id,
                ]);
            }

            $expense->delete();
        });

        return back();
    }
}
