<?php

namespace App\Services;

use App\Models\Due;
use App\Models\Movement;

/**
 * Mantiene el libro de acuerdo con el estado de una cuota cobrada a mano.
 * Es idempotente: deja un solo movimiento vigente (el que no tiene
 * contra-asiento) cuando la cuota está pagada, y ninguno cuando no.
 * Las correcciones son contra-asientos; las filas nunca se borran.
 */
class CuotaLedger
{
    /** Pone el libro al día con el estado actual de $due. $pot solo importa si está pagada. */
    public function sync(Due $due, ?string $pot, ?int $memberId): void
    {
        $vigente = $this->vigente($due);

        if ($vigente && ($due->status !== 'paid' || $vigente->pot !== $pot || $vigente->amount_cents !== $due->amount_cents)) {
            Movement::withoutGlobalScopes()->create([
                'club_id' => $due->club_id,
                'pot' => $vigente->pot,
                'kind' => 'cuota',
                'amount_cents' => -$vigente->amount_cents,
                'occurred_on' => now()->toDateString(),
                'reverses_movement_id' => $vigente->id,
                'due_id' => $due->id,
                'created_by_member_id' => $memberId,
            ]);
            $vigente = null;
        }

        if ($due->status === 'paid' && ! $vigente) {
            Movement::withoutGlobalScopes()->create([
                'club_id' => $due->club_id,
                'pot' => $pot,
                'kind' => 'cuota',
                'amount_cents' => $due->amount_cents,
                'occurred_on' => now()->toDateString(),
                'due_id' => $due->id,
                'created_by_member_id' => $memberId,
            ]);
        }
    }

    /** El asiento original de la cuota que todavía no fue contra-asentado. */
    private function vigente(Due $due): ?Movement
    {
        return Movement::withoutGlobalScopes()
            ->where('club_id', $due->club_id)
            ->where('due_id', $due->id)
            ->where('kind', 'cuota')
            ->vigentes()
            ->first();
    }
}
