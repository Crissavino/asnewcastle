<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    /** @use HasFactory<\Database\Factories\ClubFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'city',
        'league',
        'crest_path',
        'stripe_account_id',
        'stripe_onboarded_at',
        'monthly_fee_cents',
        'currency',
        'standings_json',
    ];

    protected function casts(): array
    {
        return [
            'stripe_onboarded_at' => 'datetime',
            'standings_json' => 'array',
            'monthly_fee_cents' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }
}
