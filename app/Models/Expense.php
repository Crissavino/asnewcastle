<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToClub;

    public const CATEGORIES = ['referee', 'pitch', 'league', 'gear', 'water', 'other'];

    protected $fillable = [
        'club_id',
        'member_id',
        'event_id',
        'category',
        'description',
        'amount_cents',
        'spent_on',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
