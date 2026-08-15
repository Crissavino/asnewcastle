<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'due_id',
        'stripe_payment_intent_id',
        'amount_cents',
        'application_fee_cents',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'amount_cents' => 'integer',
            'application_fee_cents' => 'integer',
        ];
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }
}
