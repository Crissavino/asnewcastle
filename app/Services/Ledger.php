<?php

namespace App\Services;

use App\Models\Movement;
use Illuminate\Support\Collection;

/**
 * Lee el libro de movimientos del club activo (el global scope filtra por
 * club_id). Los saldos no se guardan: son la suma de las filas con signo.
 */
class Ledger
{
    /** @return array{cuenta: int, cash: int} */
    public function balances(): array
    {
        $sums = Movement::query()
            ->selectRaw('pot, SUM(amount_cents) as total')
            ->groupBy('pot')
            ->pluck('total', 'pot');

        $balances = [];
        foreach (Movement::POTS as $pot) {
            $balances[$pot] = (int) ($sums[$pot] ?? 0);
        }

        return $balances;
    }

    public function balance(string $pot): int
    {
        return $this->balances()[$pot] ?? 0;
    }

    /** @return Collection<int, Movement> */
    public function history(int $limit = 50): Collection
    {
        return Movement::query()
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
