<?php

use App\Models\Due;
use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

it('la caja (recaudación, histórico y deudores) la ve todo el plantel', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($player)->create(['amount_cents' => 28000]);

    $this->actingAs($player->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->has('caja')                          // la caja llega al jugador
            ->has('caja.debtors')
            ->where('caja.owed_all_cents', 28000)
            ->missing('config')                    // la configuración NO
            ->missing('gastos')
        );
});

it('el manager sí recibe la configuración de cuota', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->has('caja')->has('config'));
});

it('el dueño alterna a vista jugador: rol efectivo player y sin config', function () {
    config(['app.owner_phone' => '+40750471080']);
    $owner = Member::factory()->manager()->create();
    $owner->user->update(['phone' => '+40750471080']);

    // Arranca como admin
    $this->actingAs($owner->user)->get('/cuota')->assertInertia(fn (Assert $page) => $page
        ->where('is_owner', true)
        ->where('member.role', 'manager')
        ->has('config'));

    // Toca el toggle
    $this->actingAs($owner->user)->post('/ver-como')->assertRedirect();

    // Ahora ve como jugador: sin config y rol efectivo player
    $this->actingAs($owner->user)->get('/cuota')->assertInertia(fn (Assert $page) => $page
        ->where('viewing_as_player', true)
        ->where('member.role', 'player')
        ->missing('config'));

    // Y vuelve a admin
    $this->actingAs($owner->user)->post('/ver-como')->assertRedirect();
    $this->actingAs($owner->user)->get('/cuota')->assertInertia(fn (Assert $page) => $page
        ->where('member.role', 'manager')->has('config'));
});

it('un manager que no es el dueño no ve el toggle ni puede usarlo', function () {
    config(['app.owner_phone' => '+40750471080']);
    $manager = Member::factory()->manager()->create();
    $manager->user->update(['phone' => '+40711111111']);

    $this->actingAs($manager->user)->post('/ver-como')->assertForbidden();

    $this->actingAs($manager->user)->get('/cuota')->assertInertia(fn (Assert $page) => $page
        ->where('is_owner', false)
        ->where('member.role', 'manager')   // su vista no cambia
        ->has('config'));
});

it('los permisos reales del dueño siguen intactos aunque vea como jugador', function () {
    config(['app.owner_phone' => '+40750471080']);
    $owner = Member::factory()->manager()->create();
    $owner->user->update(['phone' => '+40750471080']);
    $owner->club->update(['monthly_fee_cents' => 28000]);

    $this->actingAs($owner->user)->post('/ver-como'); // pasa a vista jugador

    // Aunque "vea como jugador", como manager real todavía puede operar
    $this->actingAs($owner->user)
        ->patch('/cuota/config', ['monthly_fee_cents' => 30000])
        ->assertRedirect();

    expect($owner->club->fresh()->monthly_fee_cents)->toBe(30000);
});
