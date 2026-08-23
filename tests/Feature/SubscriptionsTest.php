<?php

use App\Models\Club;
use App\Models\Due;
use App\Models\Member;
use App\Services\Stripe\StripeGateway;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeStripeGateway;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->stripe = new FakeStripeGateway;
    $this->app->instance(StripeGateway::class, $this->stripe);
    $this->app->instance(WhatsAppChannel::class, new FakeWhatsAppChannel);
});

function readyClub(array $attrs = []): Club
{
    return Club::factory()->create(array_merge([
        'monthly_fee_cents' => 28000,
        'subscription_discount_cents' => 3000,
        'stripe_account_id' => 'acct_x',
        'stripe_onboarded_at' => now(),
    ], $attrs));
}

it('el monto suscripto es la cuota base menos el descuento', function () {
    $club = readyClub(['monthly_fee_cents' => 28000, 'subscription_discount_cents' => 3000]);
    $member = Member::factory()->for($club)->create();

    expect($member->subscribedFeeCents())->toBe(25000);
});

it('el jugador se suscribe y va al checkout de suscripción', function () {
    $member = Member::factory()->for(readyClub())->create();

    $this->actingAs($member->user)->post('/cuota/suscribir')
        ->assertRedirect('https://checkout.stripe.test/sub/member-'.$member->id);

    expect($this->stripe->subscriptionsFor)->toBe([$member->id]);
});

it('un becado no puede suscribirse', function () {
    $member = Member::factory()->for(readyClub())->create(['fee_type' => 'becado']);

    $this->actingAs($member->user)->post('/cuota/suscribir')->assertStatus(400);
});

it('sin onboarding de Stripe no hay suscripción', function () {
    $member = Member::factory()->for(Club::factory()->create(['monthly_fee_cents' => 28000]))->create();

    $this->actingAs($member->user)->post('/cuota/suscribir')->assertStatus(400);
});

it('no se re-suscribe si ya está activo', function () {
    $member = Member::factory()->for(readyClub())->create(['subscription_status' => 'active']);

    $this->actingAs($member->user)->post('/cuota/suscribir')->assertStatus(400);
});

it('solo el manager corta el débito de un jugador', function () {
    $manager = Member::factory()->manager()->create();
    $manager->club->update(['stripe_account_id' => 'acct_x', 'stripe_onboarded_at' => now()]);
    $player = Member::factory()->for($manager->club)->create([
        'subscription_status' => 'active', 'stripe_subscription_id' => 'sub_1',
    ]);

    $this->actingAs($player->user)->post("/plantel/{$player->id}/suscripcion/cancelar")->assertForbidden();

    $this->actingAs($manager->user)->post("/plantel/{$player->id}/suscripcion/cancelar")->assertRedirect();

    expect($this->stripe->canceledFor)->toBe([$player->id])
        ->and($player->fresh()->subscription_status)->toBe('canceled')
        ->and($player->fresh()->stripe_subscription_id)->toBeNull();
});

it('no se corta el débito de un miembro de otro club', function () {
    $manager = Member::factory()->manager()->create();
    $otro = Member::factory()->create(['subscription_status' => 'active']); // otro club

    $this->actingAs($manager->user)->post("/plantel/{$otro->id}/suscripcion/cancelar")->assertNotFound();
});

it('el manager configura el descuento por débito automático', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->patch('/cuota/config', [
        'monthly_fee_cents' => 28000,
        'subscription_discount_cents' => 3000,
    ])->assertRedirect();

    expect($manager->club->fresh()->subscription_discount_cents)->toBe(3000);
});

it('el job mensual saltea a los suscriptos', function () {
    $club = Club::factory()->create(['monthly_fee_cents' => 28000]);
    $normal = Member::factory()->for($club)->create();
    $subscripto = Member::factory()->for($club)->create(['subscription_status' => 'active']);

    $this->artisan('cuotas:generar')->assertSuccessful();

    expect(Due::withoutGlobalScopes()->where('member_id', $normal->id)->count())->toBe(1)
        ->and(Due::withoutGlobalScopes()->where('member_id', $subscripto->id)->count())->toBe(0);
});

it('la pantalla de cuota comparte el estado de suscripción del jugador', function () {
    $member = Member::factory()->for(readyClub())->create();

    $this->actingAs($member->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.subscribed_fee_cents', 25000)
            ->where('subscription.discount_cents', 3000)
            ->where('subscription.status', null)
        );
});
