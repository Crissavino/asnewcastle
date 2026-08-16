<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;

    protected $fillable = [
        'club_id',
        'user_id',
        'role',
        'shirt_number',
        'position',
        'preferred_foot',
        'availability',
        'fee_type',
        'custom_fee_cents',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'availability' => 'array',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
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

    /** El alta está completa cuando el wizard cargó nombre, puesto, dorsal y disponibilidad. */
    public function profileComplete(): bool
    {
        return $this->user->name !== null
            && $this->position !== null
            && $this->shirt_number !== null
            && ! empty($this->availability);
    }
}
