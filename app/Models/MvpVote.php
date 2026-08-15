<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MvpVote extends Model
{
    protected $fillable = [
        'event_id',
        'voter_member_id',
        'voted_member_id',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function voted(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'voted_member_id');
    }
}
