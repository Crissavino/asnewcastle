<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El libro: una fila por cada hecho que mueve plata. Los dos saldos
 * (cuenta y cash) son la suma de estas filas. amount_cents va con signo.
 */
class Movement extends Model
{
    /** @use HasFactory<\Database\Factories\MovementFactory> */
    use BelongsToClub, HasFactory;

    public const POTS = ['cuenta', 'cash'];

    public const KINDS = ['payout', 'cuota', 'gasto', 'transferencia', 'ingreso', 'ajuste', 'fee', 'saldo_inicial'];

    protected $fillable = [
        'club_id',
        'pot',
        'kind',
        'amount_cents',
        'occurred_on',
        'group_uuid',
        'reverses_movement_id',
        'expense_id',
        'due_id',
        'mollie_payout_id',
        'note',
        'created_by_member_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }

    /** El movimiento que este contra-asiento anula. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /**
     * Los asientos que todavía cuentan: los originales (no son contra-asiento de
     * nadie) que nadie contra-asentó.
     *
     * Es LA definición de "este asiento sigue vigente", y vive acá sola: la
     * consumen CuotaLedger y PayoutLedger. Duplicada, el día que haya que
     * cambiarla (un contra-asiento del contra-asiento, un `reverses` encadenado)
     * hay que acertarle a dos lugares o el saldo se parte.
     *
     * La subconsulta va sin global scopes a propósito: esto corre también en
     * comandos y webhooks, donde no hay club activo y el filtro por club_id lo
     * pone a mano quien llama.
     */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('reverses_movement_id')
            ->whereNotIn('id', self::withoutGlobalScopes()
                ->whereNotNull('reverses_movement_id')->select('reverses_movement_id'));
    }
}
