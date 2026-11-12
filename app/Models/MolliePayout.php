<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un depósito de Mollie en el banco del club: la plata de las cuotas cobradas
 * online, juntada y transferida al IBAN. Es el espejo del hecho que informa el
 * webhook; el movimiento en el libro se escribe aparte (PayoutLedger).
 *
 * amount_cents es el NETO depositado. fee_cents es la comisión de Mollie y
 * puede quedar en null: si la API no la informa no se inventa.
 */
class MolliePayout extends Model
{
    use BelongsToClub;

    protected $fillable = [
        'club_id',
        'mollie_payout_id',
        'status',
        'amount_cents',
        'fee_cents',
        'currency',
        'pending_reason',
        'completed_at',
    ];

    /** Quedó registrado pero su plata no entró a ningún saldo. */
    public function sinAcreditar(): bool
    {
        return $this->pending_reason !== null;
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
