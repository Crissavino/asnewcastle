<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Expense;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);

        // El evento atado tiene que ser del club (el scope global ya filtra)
        if (! empty($validated['event_id']) && ! Event::whereKey($validated['event_id'])->exists()) {
            abort(404);
        }

        Expense::create([
            ...$validated,
            'member_id' => app(CurrentClub::class)->member()->id,
        ]);

        return back();
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        Gate::authorize('create', Event::class);
        app(CurrentClub::class)->assertOwns($expense);

        $expense->delete();

        return back();
    }
}
