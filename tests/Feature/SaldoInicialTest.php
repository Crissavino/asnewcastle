<?php

use App\Models\Member;
use App\Models\Movement;
use App\Services\Ledger;
use App\Support\CurrentClub;

it('en dry no escribe nada', function () {
    Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial 225 0')->assertSuccessful();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('con go crea un saldo inicial por bolsillo', function () {
    $m = Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial 225 0 go')->assertSuccessful();

    expect(Movement::withoutGlobalScopes()->where('club_id', $m->club_id)->count())->toBe(2);
    app(CurrentClub::class)->set($m->club, $m);
    expect(app(Ledger::class)->balances())->toBe(['cuenta' => 22500, 'cash' => 0]);
});

it('no se puede correr dos veces', function () {
    Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial 225 0 go');
    $this->artisan('app:saldo-inicial 999 0 go')->assertFailed();
    expect(Movement::withoutGlobalScopes()->where('kind', 'saldo_inicial')->count())->toBe(2);
});

it('rechaza montos inválidos', function () {
    Member::factory()->manager()->create();
    $this->artisan('app:saldo-inicial abc 0 go')->assertFailed();
    $this->artisan('app:saldo-inicial 1.5 0 go')->assertFailed();
    expect(Movement::withoutGlobalScopes()->count())->toBe(0);
});

it('cada club queda con su club_id y el que ya tiene se saltea', function () {
    $a = Member::factory()->manager()->create();
    $b = Member::factory()->manager()->create();
    Movement::factory()->for($a->club)->create(['kind' => 'saldo_inicial', 'amount_cents' => 100]);

    $this->artisan('app:saldo-inicial 225 10 go')->assertSuccessful();

    expect(Movement::withoutGlobalScopes()->where('club_id', $a->club_id)->count())->toBe(1)
        ->and(Movement::withoutGlobalScopes()->where('club_id', $b->club_id)->pluck('amount_cents', 'pot')->all())
        ->toBe(['cuenta' => 22500, 'cash' => 1000]);
});
