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
    $this->stripe = new FakeStripeGateway();
    $this->app->instance(StripeGateway::class, $this->stripe);
    $this->whatsapp = new FakeWhatsAppChannel();
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

it('genera las cuotas del mes para los miembros activos, una sola vez', function () {
    $club = Club::factory()->create(['monthly_fee_cents' => 12000]);
    Member::factory()->for($club)->count(3)->create();
    Member::factory()->for($club)->create(['left_at' => now()]); // dado de baja

    $this->artisan('cuotas:generar')->assertSuccessful();
    $this->artisan('cuotas:generar')->assertSuccessful(); // idempotente

    $dues = Due::withoutGlobalScopes()->where('club_id', $club->id)->get();
    expect($dues)->toHaveCount(3)
        ->and($dues->first()->amount_cents)->toBe(12000)
        ->and($dues->first()->status)->toBe('pending');
});

it('el jugador ve su cuota pero no la caja del club', function () {
    $member = Member::factory()->create();
    Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('my_due.amount_cents', 12000)
            ->where('my_due.status', 'pending')
            ->missing('caja')
        );
});

it('el manager ve la caja con los que deben, sin teléfonos', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create();
    $deudor = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($deudor)->create();

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('caja.paid_count', 1)
            ->where('caja.total_count', 2)
            ->where('caja.collected_cents', 12000)
            ->where('caja.target_cents', 24000)
            ->has('caja.debtors', 1)
            ->missing('caja.debtors.0.phone')
        );
});

it('pagar manda al checkout de Stripe de la cuenta conectada', function () {
    $member = Member::factory()->create();
    $member->club->update(['stripe_account_id' => 'acct_x', 'stripe_onboarded_at' => now()]);
    $due = Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)
        ->post("/cuota/{$due->id}/pagar")
        ->assertRedirect('https://checkout.stripe.test/pay/due-'.$due->id);

    expect($this->stripe->checkoutsFor)->toBe([$due->id]);
});

it('no se puede pagar la cuota de otro jugador ni la de otro club', function () {
    $member = Member::factory()->create();
    $member->club->update(['stripe_onboarded_at' => now()]);

    $otro = Member::factory()->for($member->club)->create();
    $dueAjena = Due::factory()->forMember($otro)->create();
    $dueOtroClub = Due::factory()->create();

    $this->actingAs($member->user)->post("/cuota/{$dueAjena->id}/pagar")->assertForbidden();
    $this->actingAs($member->user)->post("/cuota/{$dueOtroClub->id}/pagar")->assertNotFound();
});

it('sin onboarding de Stripe no hay checkout', function () {
    $member = Member::factory()->create();
    $due = Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)->post("/cuota/{$due->id}/pagar")->assertStatus(400);
});

it('el onboarding lo arranca solo el manager y crea la cuenta Express', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($player->user)->post('/stripe/onboarding')->assertForbidden();

    $this->actingAs($manager->user)
        ->post('/stripe/onboarding')
        ->assertRedirect('https://connect.stripe.test/onboarding/acct_test_'.$manager->club_id);

    expect($manager->club->fresh()->stripe_account_id)->toBe('acct_test_'.$manager->club_id);
});

it('a la vuelta del onboarding con la cuenta habilitada se marca el club', function () {
    $manager = Member::factory()->manager()->create();
    $manager->club->update(['stripe_account_id' => 'acct_x']);

    $this->actingAs($manager->user)->get('/stripe/retorno')->assertRedirect(route('cuota'));

    expect($manager->club->fresh()->stripe_onboarded_at)->not->toBeNull();
});

it('reclamar por WhatsApp va solo a los que deben, y solo lo hace el manager', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create();
    $deudor = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($deudor)->create();

    $this->actingAs($deudor->user)->post('/cuota/reclamar')->assertForbidden();

    $this->actingAs($manager->user)->post('/cuota/reclamar');

    expect($this->whatsapp->templateRecipients())->toBe([$deudor->user->phone]);
});
