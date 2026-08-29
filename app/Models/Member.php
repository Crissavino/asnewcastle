<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = [
        'club_id',
        'user_id',
        'role',
        'hidden',
        'shirt_number',
        'position',
        'preferred_foot',
        'availability',
        'fee_type',
        'custom_fee_cents',
        'stripe_customer_id',
        'stripe_subscription_id',
        'subscription_status',
        'mollie_customer_id',
        'mollie_subscription_id',
        'vestuario_read_at',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'availability' => 'array',
            'hidden' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'vestuario_read_at' => 'datetime',
            'shirt_number' => 'integer',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /** Cuerpo técnico: es staff, no jugador (no dorsal, no cuota, no juega). */
    public function isCoach(): bool
    {
        return $this->role === 'coach';
    }

    public const FEE_TYPES = ['normal', 'becado', 'custom'];

    /** El monto que le corresponde este mes, o null si es becado (no genera cuota). */
    public function monthlyFeeCents(): ?int
    {
        return match ($this->fee_type) {
            'becado' => null,
            'custom' => $this->custom_fee_cents,
            default => $this->club->monthly_fee_cents,
        };
    }

    /** ¿Tiene el débito automático activo? */
    public function isSubscribed(): bool
    {
        return $this->subscription_status === 'active';
    }

    /**
     * Monto mensual con el descuento por suscribirse, o null si es becado.
     * Es la cuota base menos el descuento del club (nunca por debajo de 0).
     */
    public function subscribedFeeCents(): ?int
    {
        $base = $this->monthlyFeeCents();

        if ($base === null || $base === 0) {
            return null;
        }

        return max($base - $this->club->subscription_discount_cents, 0);
    }

    /**
     * El alta está completa según el rol: el técnico solo necesita nombre; el
     * jugador, además, puesto, dorsal y disponibilidad.
     */
    public function profileComplete(): bool
    {
        if ($this->user->name === null) {
            return false;
        }

        if ($this->isCoach()) {
            return true;
        }

        return $this->position !== null
            && $this->shirt_number !== null
            && ! empty($this->availability);
    }
}
