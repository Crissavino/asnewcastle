<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compromiso de pago de un deudor ("pago el 20/09") o su aviso de que no
 * puede pagar. El estado de una promesa se resuelve al leer (settle):
 * cubre las cuotas pendientes de los períodos que existían al prometer —
 * la cuota nueva del mes siguiente no rompe una promesa vieja.
 */
class DuePromise extends Model
{
    use BelongsToClub;

    public const KIND_PROMISE = 'promise';

    public const KIND_CANT_PAY = 'cant_pay';

    protected $fillable = [
        'club_id',
        'member_id',
        'kind',
        'promised_for',
        'debt_cents',
        'status',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'promised_for' => 'date',
            'debt_cents' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Promesas con fecha ya vencida que todavía figuran activas. */
    public function scopeStale(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PROMISE)
            ->where('status', 'active')
            ->whereDate('promised_for', '<', today());
    }

    /**
     * Cierra las promesas vencidas: cumplida si las cuotas que cubría
     * (períodos hasta el mes en que se prometió) ya no están pendientes,
     * rota si la deuda de entonces sigue viva. Idempotente y perezoso:
     * se llama al leer, no hace falta ningún cron.
     */
    public static function settle(?int $memberId = null): void
    {
        $stale = static::query()->stale()
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->get();

        foreach ($stale as $promise) {
            $coveredPending = Due::withoutGlobalScopes()
                ->where('member_id', $promise->member_id)
                ->where('status', 'pending')
                ->whereDate('period', '<=', $promise->created_at->copy()->startOfMonth())
                ->exists();

            $promise->update(['status' => $coveredPending ? 'broken' : 'kept']);
        }
    }
}
