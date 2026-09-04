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

it('el jugador ve su cuota y la caja, pero no la gestión (config/gastos)', function () {
    $member = Member::factory()->create();
    Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('my_due.amount_cents', 12000)
            ->where('my_due.status', 'pending')
            ->has('caja')          // ahora la caja la ve todo el plantel
            ->missing('config')    // pero no la configuración
            ->missing('gastos')
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
            ->where('caja.up_to_date', 1)
            ->where('caja.players_total', 2)
            ->where('caja.collected_cents', 12000)
            ->where('caja.target_cents', 24000)
            ->has('caja.debtors', 1)
            ->missing('caja.debtors.0.phone')
        );
});

it('el becado figura al día en la caja: hacia afuera no se distingue', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create();
    $deudor = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($deudor)->create();                          // pendiente
    Member::factory()->for($manager->club)->create(['fee_type' => 'becado']); // becado: no genera cuota

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            // 3 jugadores, 1 debe → 2 al día (el becado cuenta al día)
            ->where('caja.players_total', 3)
            ->where('caja.up_to_date', 2)
            ->has('caja.debtors', 1) // solo el deudor; el becado no aparece
        );
});

it('un jugador Normal sin cuota generada figura como deudor al abrir la caja', function () {
    // Se sumó a mitad de mes o pasó de becado a normal: nunca corrió el job
    // que le genera la cuota. Antes figuraba "al día" (bug); ahora la caja se
    // la completa y aparece como deudor accionable.
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create();
    $sinCuota = Member::factory()->for($manager->club)->create(['fee_type' => 'normal']);
    // Ojo: NO le creamos ninguna cuota.

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('caja.players_total', 2)
            ->where('caja.up_to_date', 1)          // solo el manager; el otro debe
            ->has('caja.debtors', 1)
            ->where('caja.debtors.0.name', $sinCuota->user->name)
            ->where('caja.debtors.0.due_id', fn ($id) => $id !== null) // accionable
        );

    // La cuota quedó realmente creada en la base (idempotente: no duplica)
    $this->actingAs($manager->user)->get('/cuota');
    expect(Due::withoutGlobalScopes()->where('member_id', $sinCuota->id)->count())->toBe(1);
});

it('abrir la caja NO pisa ni duplica una cuota ya pagada', function () {
    // Garantía contra el incidente: si un jugador ya tiene su cuota del mes
    // pagada, ensurePeriodDues (que corre al abrir la caja) la encuentra y la
    // deja intacta — nunca la vuelve a pending ni crea una segunda.
    $manager = Member::factory()->manager()->create();
    $jugador = Member::factory()->for($manager->club)->create(['fee_type' => 'normal']);
    $pagada = Due::factory()->forMember($jugador)->paid()->create();

    $this->actingAs($manager->user)->get('/cuota');
    $this->actingAs($manager->user)->get('/cuota'); // dos veces, por las dudas

    $suyas = Due::withoutGlobalScopes()->where('member_id', $jugador->id)->get();
    expect($suyas)->toHaveCount(1)
        ->and($suyas->first()->id)->toBe($pagada->id)
        ->and($suyas->first()->status)->toBe('paid');
});

it('el suscripto al débito automático no recibe una cuota manual por la caja', function () {
    $manager = Member::factory()->manager()->create();
    $suscripto = Member::factory()->for($manager->club)
        ->create(['fee_type' => 'normal', 'subscription_status' => 'active']);

    $this->actingAs($manager->user)->get('/cuota');

    // Su cuota sale por la invoice del débito, no por acá
    expect(Due::withoutGlobalScopes()->where('member_id', $suscripto->id)->count())->toBe(0);
});

it('pagar manda al checkout de Stripe de la cuenta conectada', function () {
    $member = Member::factory()->create();
    $member->club->update(['stripe_account_id' => 'acct_x', 'stripe_onboarded_at' => now()]);
    $due = Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)
        ->postJson("/cuota/{$due->id}/pagar")
        ->assertOk()
        ->assertJson(['url' => 'https://checkout.stripe.test/pay/due-'.$due->id]);

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

it('el manager marca cuotas en efectivo o las condona, y la caja lo refleja', function () {
    $manager = Member::factory()->manager()->create();
    $due = Due::factory()->forMember($manager)->create();
    $condonado = Member::factory()->for($manager->club)->create();
    $dueCondonada = Due::factory()->forMember($condonado)->create();

    $this->actingAs($manager->user)->post("/cuota/{$due->id}/estado", ['status' => 'paid'])->assertRedirect();
    $this->actingAs($manager->user)->post("/cuota/{$dueCondonada->id}/estado", ['status' => 'waived'])->assertRedirect();

    expect($due->fresh()->status)->toBe('paid')
        ->and($dueCondonada->fresh()->status)->toBe('waived');

    // La condonada no suma al objetivo ni como deuda, pero el jugador SÍ figura
    // al día (2 de 2): hacia afuera no se distingue al condonado/becado.
    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('caja.players_total', 2)
            ->where('caja.up_to_date', 2)
            ->where('caja.target_cents', 12000)
            ->has('caja.debtors', 0)
        );
});

it('una cuota pagada por Stripe no se puede pisar a mano, y un player tampoco toca nada', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $due = Due::factory()->forMember($player)->paid()->create();
    $due->payments()->create([
        'stripe_payment_intent_id' => 'pi_lock',
        'amount_cents' => 12000,
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    $this->actingAs($manager->user)->post("/cuota/{$due->id}/estado", ['status' => 'pending'])->assertStatus(400);
    $this->actingAs($player->user)->post("/cuota/{$due->id}/estado", ['status' => 'paid'])->assertForbidden();
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
