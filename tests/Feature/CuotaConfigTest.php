<?php

use App\Models\Club;
use App\Models\Due;
use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

it('genera cuotas según el tipo: normal, personalizada y becado sin cuota', function () {
    $club = Club::factory()->create(['monthly_fee_cents' => 12000]);
    $normal = Member::factory()->for($club)->create();
    $custom = Member::factory()->for($club)->create(['fee_type' => 'custom', 'custom_fee_cents' => 6000]);
    $becado = Member::factory()->for($club)->create(['fee_type' => 'becado']);

    $this->artisan('cuotas:generar')->assertSuccessful();

    $dues = Due::withoutGlobalScopes()->where('club_id', $club->id)->get()->keyBy('member_id');
    expect($dues)->toHaveCount(2)
        ->and($dues->get($normal->id)->amount_cents)->toBe(12000)
        ->and($dues->get($custom->id)->amount_cents)->toBe(6000)
        ->and($dues->has($becado->id))->toBeFalse();
});

it('el manager edita la cuota del club y las pendientes del mes toman el monto nuevo', function () {
    $manager = Member::factory()->manager()->create();
    $manager->club->update(['monthly_fee_cents' => 12000]);
    $due = Due::factory()->forMember($manager)->create(['amount_cents' => 12000]);
    $pagada = Member::factory()->for($manager->club)->create();
    $duePagada = Due::factory()->forMember($pagada)->paid()->create(['amount_cents' => 12000]);

    $this->actingAs($manager->user)
        ->patch('/cuota/config', ['monthly_fee_cents' => 15000])
        ->assertRedirect();

    expect($manager->club->fresh()->monthly_fee_cents)->toBe(15000)
        ->and($due->fresh()->amount_cents)->toBe(15000)   // pendiente: se ajusta
        ->and($duePagada->fresh()->amount_cents)->toBe(12000); // pagada: no se toca

    // Un jugador no puede
    $this->actingAs($pagada->user)->patch('/cuota/config', ['monthly_fee_cents' => 1])->assertForbidden();
});

it('becar a un jugador borra su cuota pendiente del mes, y el tipo custom la ajusta', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $due = Due::factory()->forMember($player)->create(['amount_cents' => 12000]);

    $this->actingAs($manager->user)
        ->post("/plantel/{$player->id}/cuota", ['fee_type' => 'custom', 'custom_fee_cents' => 5000])
        ->assertRedirect();

    expect($due->fresh()->amount_cents)->toBe(5000);

    $this->actingAs($manager->user)
        ->post("/plantel/{$player->id}/cuota", ['fee_type' => 'becado'])
        ->assertRedirect();

    expect($player->fresh()->fee_type)->toBe('becado')
        ->and(Due::withoutGlobalScopes()->whereKey($due->id)->exists())->toBeFalse();
});

it('una cuota pagada por Stripe no se toca al cambiar el tipo', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $due = Due::factory()->forMember($player)->create();
    $due->payments()->create([
        'stripe_payment_intent_id' => 'pi_fee_lock',
        'amount_cents' => 12000,
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    $this->actingAs($manager->user)
        ->post("/plantel/{$player->id}/cuota", ['fee_type' => 'becado'])
        ->assertRedirect();

    expect(Due::withoutGlobalScopes()->whereKey($due->id)->exists())->toBeTrue();
});

it('el tipo de cuota es privado: el plantel ve al becado al día y sin tipo', function () {
    $manager = Member::factory()->manager()->create();
    $becado = Member::factory()->for($manager->club)->create(['fee_type' => 'becado']);
    $player = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($player)->create(); // este sí debe

    $becadoRow = fn ($rows) => collect($rows)->firstWhere('id', $becado->id);

    $this->actingAs($player->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('plantel', fn ($rows) => $becadoRow($rows)['due_status'] === 'paid'
                && ! array_key_exists('fee_type', $becadoRow($rows)))
            ->missing('config')
        );

    // Un jugador tampoco puede tocar tipos de cuota
    $this->actingAs($player->user)
        ->post("/plantel/{$becado->id}/cuota", ['fee_type' => 'normal'])
        ->assertForbidden();

    // Y de otro club, ni el manager
    $ajeno = Member::factory()->create();
    $this->actingAs($manager->user)
        ->post("/plantel/{$ajeno->id}/cuota", ['fee_type' => 'becado'])
        ->assertNotFound();
});
