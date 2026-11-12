<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Fuera de una request nadie corre el middleware CurrentClub, así que los
 * tests que consultan el Ledger o Movement fijan el club activo a mano.
 */
function fijarClub(App\Models\Member $member): void
{
    app(App\Support\CurrentClub::class)->set($member->club, $member);
}
