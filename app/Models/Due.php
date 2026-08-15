<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Due extends Model
{
    /** @use HasFactory<\Database\Factories\DueFactory> */
    use BelongsToClub, HasFactory;

    protected $fillable = [
        'club_id',
        'member_id',
        'period',
        'amount_cents',
        'status',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'due_date' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
