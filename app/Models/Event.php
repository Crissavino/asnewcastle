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
        'mvp_opened_at',
        'mvp_closed_at',
        'cancelled_at',
        'goals_for',
        'goals_against',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'starts_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
            'mvp_opened_at' => 'datetime',
            'mvp_closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'goals_for' => 'integer',
            'goals_against' => 'integer',
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

    public function mvpVotes(): HasMany
    {
        return $this->hasMany(MvpVote::class);
    }

    public function playerRatings(): HasMany
    {
        return $this->hasMany(PlayerRating::class);
    }

    /** Un partido se considera terminado 2 horas después del arranque. */
    public function isFinished(): bool
    {
        return $this->starts_at->copy()->addHours(2)->isPast();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function hasResult(): bool
    {
        return $this->goals_for !== null && $this->goals_against !== null;
    }

    /** La votación de figura queda abierta 48hs desde el final del partido. */
    public function mvpPollOpen(): bool
    {
        return $this->mvp_opened_at !== null
            && $this->isFinished()
            && $this->starts_at->copy()->addHours(50)->isFuture();
    }
}
