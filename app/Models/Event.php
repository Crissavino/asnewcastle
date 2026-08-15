<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use BelongsToClub, HasFactory;

    protected $fillable = [
        'club_id',
        'created_by_member_id',
        'kind',
        'opponent',
        'is_home',
        'starts_at',
        'venue',
        'kit',
        'notes',
        'notified_at',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'starts_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_by_member_id');
    }

    public function isMatch(): bool
    {
        return $this->kind === 'match';
    }
}
