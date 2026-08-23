<?php

namespace App\Models;

use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
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
        'subscription_discount_cents',
        'currency',
        'standings_json',
        'standings_url',
    ];

    protected function casts(): array
    {
        return [
            'stripe_onboarded_at' => 'datetime',
            'standings_json' => 'array',
            'monthly_fee_cents' => 'integer',
            'subscription_discount_cents' => 'integer',
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
