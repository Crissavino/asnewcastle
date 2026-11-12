<?php

use App\Models\DeviceToken;
use App\Models\Due;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Message;
use App\Models\Movement;
use App\Models\Notification;
use App\Services\Push\PushSender;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePushSender;

it('todo el plantel ve quién está al día y quién debe, y el resumen de caja', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($manager)->paid()->create();
    Due::factory()->forMember($player)->create(); // pendiente

    // El jugador ve el estado del plantel, el saldo y la caja (recaudación,
    // histórico, deudores). Lo que NO ve es la gestión: config y gastos.
    $this->actingAs($player->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->has('plantel', 2)
            ->where('plantel.0.due_status', fn ($s) => in_array($s, ['paid', 'pending'], true))
            ->has('saldos')
            ->has('caja')
            ->missing('config')
            ->missing('gastos')
        );
});

it('los gastos bajan el bolsillo del que salieron, y el jugador ve el desglose por categoría', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create(['amount_cents' => 12000]);

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'referee',
        'amount_cents' => 15000,
        'spent_on' => now()->toDateString(),
        'pot' => 'cash',
    ])->assertRedirect();

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'water',
        'amount_cents' => 4000,
        'spent_on' => now()->toDateString(),
        'pot' => 'cuenta',
        'description' => 'Bidones',
    ])->assertRedirect();

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.cash', -15000)   // puede quedar en rojo
            ->where('saldos.cuenta', -4000)
            ->where('resumen.month_out_cents', 19000)
            ->where('resumen.by_category.referee', 15000)
            ->has('gastos', 2)
        );
});

it('el saldo viejo ya no se manda y en su lugar van los dos bolsillos', function () {
    $m = Member::factory()->manager()->create();
    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->missing('resumen.balance_cents')
            ->has('saldos.cuenta')->has('saldos.cash')->has('saldos.as_of')
            ->where('saldos.as_of', now()->toDateString())
        );
});

it('sin poder leer el saldo de Mollie, el tránsito va null y nunca cero', function () {
    $m = Member::factory()->manager()->create();
    fakeMollieBalanceFailure();

    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->where('saldos.transito_cents', null));
});

it('con el saldo de Mollie legible, el tránsito va en centavos', function () {
    $m = Member::factory()->manager()->create();
    fakeMollieBalance(12000, 6000);

    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->where('saldos.transito_cents', 18000));
});

it('el jugador ve los saldos pero no el detalle de cada cuota cobrada', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $otro = Member::factory()->for($manager->club)->create();
    $becado = Member::factory()->for($manager->club)->create();
    $due = Due::factory()->forMember($otro)->create(['amount_cents' => 30000]);
    $chica = Due::factory()->forMember($becado)->create(['amount_cents' => 5000]);
    $this->actingAs($manager->user)->post("/cuota/{$due->id}/estado", ['status' => 'paid', 'pot' => 'cash']);
    $this->actingAs($manager->user)->post("/cuota/{$chica->id}/estado", ['status' => 'paid', 'pot' => 'cash']);

    $this->actingAs($player->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->has('saldos')
            ->where('movimientos', fn ($ms) => collect($ms)
                ->where('kind', 'cuota')
                ->every(fn ($mv) => ! isset($mv['due_id']) && ! isset($mv['member_name'])))
            // Las dos cuotas del día van en UNA fila, y SIN monto: el monto
            // delata al becado o al de cuota personalizada.
            ->where('movimientos', fn ($ms) => collect($ms)->where('kind', 'cuota')->count() === 1)
            ->where('movimientos', fn ($ms) => collect($ms)
                ->where('kind', 'cuota')
                ->every(fn ($mv) => $mv['count'] === 2 && ! isset($mv['amount_cents']) && ! isset($mv['pot'])))
        );
});

it('una sola cuota cobrada en el día tampoco le muestra el monto al jugador', function () {
    // El caso que el agrupado por día NO tapa si además se manda el monto: un
    // solo monto suelto, cruzado con quién pasó de "debe" a "al día" en el
    // plantel (que ve todo el mundo), identifica la cuota de esa persona.
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $becado = Member::factory()->for($manager->club)->create();
    $chica = Due::factory()->forMember($becado)->create(['amount_cents' => 5000]);
    $this->actingAs($manager->user)->post("/cuota/{$chica->id}/estado", ['status' => 'paid', 'pot' => 'cash']);

    $this->actingAs($player->user)->get('/cuota')
        ->assertInertia(function (Assert $page) {
            $cuotas = collect($page->toArray()['props']['movimientos'])->where('kind', 'cuota');

            expect($cuotas)->toHaveCount(1)
                ->and($cuotas->first())->not->toHaveKey('amount_cents')
                ->and($cuotas->first())->not->toHaveKey('pot')
                ->and($cuotas->first())->not->toHaveKey('due_id')
                ->and($cuotas->first()['count'])->toBe(1);
        });

    // Y el saldo de arriba sigue teniendo la plata: lo que se esconde es la
    // fila, no el dinero.
    $this->actingAs($player->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->where('saldos.cash', 5000));
});

it('dos cuotas del mismo día en bolsillos distintos siguen siendo una sola fila', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $uno = Member::factory()->for($manager->club)->create();
    $otro = Member::factory()->for($manager->club)->create();
    $dueUno = Due::factory()->forMember($uno)->create(['amount_cents' => 30000]);
    $dueOtro = Due::factory()->forMember($otro)->create(['amount_cents' => 5000]);
    $this->actingAs($manager->user)->post("/cuota/{$dueUno->id}/estado", ['status' => 'paid', 'pot' => 'cash']);
    $this->actingAs($manager->user)->post("/cuota/{$dueOtro->id}/estado", ['status' => 'paid', 'pot' => 'cuenta']);

    $this->actingAs($player->user)->get('/cuota')
        ->assertInertia(function (Assert $page) {
            $cuotas = collect($page->toArray()['props']['movimientos'])->where('kind', 'cuota');

            // Partir por bolsillo volvería a dejar dos montos sueltos
            expect($cuotas)->toHaveCount(1)
                ->and($cuotas->first()['count'])->toBe(2);
        });
});

it('el manager sí ve el detalle', function () {
    $manager = Member::factory()->manager()->create();
    $otro = Member::factory()->for($manager->club)->create();
    $otro->user->update(['name' => 'Sergio Quiroga']);
    $due = Due::factory()->forMember($otro)->create(['amount_cents' => 30000]);
    $this->actingAs($manager->user)->post("/cuota/{$due->id}/estado", ['status' => 'paid', 'pot' => 'cash']);

    $this->actingAs($manager->user)->get('/cuota')
        ->assertInertia(function (Assert $page) use ($due) {
            $cuotas = collect($page->toArray()['props']['movimientos'])->where('kind', 'cuota');

            expect($cuotas)->toHaveCount(1)
                ->and($cuotas->first()['due_id'])->toBe($due->id)
                ->and($cuotas->first()['member_name'])->toBe('Sergio Quiroga')
                ->and($cuotas->first()['amount_cents'])->toBe(30000);
        });
});

it('la caja sigue mostrando cobrado vs adeudado y los deudores', function () {
    $m = Member::factory()->manager()->create();
    Due::factory()->forMember($m)->create();
    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->has('caja.paid_all_cents')->has('caja.owed_all_cents')->has('caja.debtors'));
});

it('el aviso de saldo negativo llega a la pantalla, no se queda en la sesión', function () {
    $m = Member::factory()->manager()->create();

    $this->actingAs($m->user)->post('/gastos', [
        'category' => 'pitch',
        'amount_cents' => 20000,
        'spent_on' => now()->toDateString(),
        'pot' => 'cash',
    ])->assertRedirect();

    $this->actingAs($m->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->where('flash.aviso_saldo_negativo', true));
});

it('los movimientos de otro club no entran en el historial', function () {
    $mio = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();
    Movement::factory()->for($ajeno->club)->create(['pot' => 'cuenta', 'amount_cents' => 99000]);

    $this->actingAs($mio->user)->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('saldos.cuenta', 0)
            ->where('movimientos', []));
});

it('un jugador no puede cargar ni borrar gastos', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($player->user)->post('/gastos', [
        'category' => 'other',
        'amount_cents' => 100,
        'spent_on' => now()->toDateString(),
    ])->assertForbidden();

    $expense = Expense::create([
        'club_id' => $manager->club_id,
        'member_id' => $manager->id,
        'category' => 'other',
        'amount_cents' => 100,
        'spent_on' => now(),
    ]);

    $this->actingAs($player->user)->delete("/gastos/{$expense->id}")->assertForbidden();
    $this->actingAs($manager->user)->delete("/gastos/{$expense->id}")->assertRedirect();
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('los gastos están aislados por club', function () {
    $manager = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create(); // otro club

    $expense = Expense::create([
        'club_id' => $ajeno->club_id,
        'member_id' => $ajeno->id,
        'category' => 'other',
        'amount_cents' => 5000,
        'spent_on' => now(),
    ]);

    // Ni lo puede borrar ni le pisa el saldo
    $this->actingAs($manager->user)->delete("/gastos/{$expense->id}")->assertNotFound();
    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->where('resumen.month_out_cents', 0));
});

it('un gasto se puede atar a un evento del club, pero no a uno ajeno', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create();
    $ajeno = Event::factory()->create();

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'referee',
        'amount_cents' => 15000,
        'spent_on' => now()->toDateString(),
        'pot' => 'cash',
        'event_id' => $event->id,
    ])->assertRedirect();

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'referee',
        'amount_cents' => 15000,
        'spent_on' => now()->toDateString(),
        'pot' => 'cash',
        'event_id' => $ajeno->id,
    ])->assertNotFound();
});

it('el día del vencimiento (10) publica en el vestuario quién pagó y quién debe', function () {
    $this->travelTo('2026-09-10 10:00');

    $manager = Member::factory()->manager()->create();
    $manager->user->update(['name' => 'Cristian Savino']);
    $deudor = Member::factory()->for($manager->club)->create();
    $deudor->user->update(['name' => 'Fabián Rodríguez']);

    Due::factory()->forMember($manager)->paid()->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);
    Due::factory()->forMember($deudor)->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);

    $this->artisan('cuotas:avisar')->assertSuccessful();

    $message = Message::withoutGlobalScopes()->where('is_system', true)->first();
    $body = json_decode($message->body, true);

    expect($body['key'])->toBe('system.dues_due')
        ->and($body['params']['names'])->toBe('Fabián')
        ->and($body['params']['paid'])->toBe(1)
        ->and($body['params']['total'])->toBe(2)
        ->and($body['params']['names'])->not->toContain('Cristian');
});

it('en un día que no es de aviso (ej. 8), no publica nada en el vestuario', function () {
    $this->travelTo('2026-09-08 10:00'); // el 8 no está en los días de recordatorio

    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);

    $this->artisan('cuotas:avisar')->assertSuccessful();

    expect(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('en un día de recordatorio (ej. 3): campanita + push a los deudores, sin publicar en el vestuario', function () {
    $this->travelTo('2026-09-03 10:00');

    $push = new FakePushSender();
    $this->app->instance(PushSender::class, $push);

    $manager = Member::factory()->manager()->create();
    $deudor = Member::factory()->for($manager->club)->create();
    DeviceToken::create(['user_id' => $deudor->user_id, 'token' => 'tok-deudor', 'platform' => 'android']);
    Due::factory()->forMember($deudor)->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);

    $this->artisan('cuotas:avisar')->assertSuccessful();

    expect(Notification::where('member_id', $deudor->id)->count())->toBe(1)
        ->and(Message::withoutGlobalScopes()->count())->toBe(0)
        ->and($push->allTokens())->toContain('tok-deudor')
        ->and($push->sent[0]['data']['url'])->toBe('/cuota');
});

it('el banner de cuota en la home aparece desde el día 5 si el jugador debe', function () {
    $this->travelTo('2026-09-06 12:00');

    $member = Member::factory()->create();
    Due::factory()->forMember($member)->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);

    $this->actingAs($member->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('dues_banner', true));
});

it('el banner de cuota no aparece antes del día 5', function () {
    $this->travelTo('2026-09-03 12:00');

    $member = Member::factory()->create();
    Due::factory()->forMember($member)->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);

    $this->actingAs($member->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('dues_banner', false));
});

it('el banner de cuota no aparece si ya pagó', function () {
    $this->travelTo('2026-09-06 12:00');

    $member = Member::factory()->create();
    Due::factory()->forMember($member)->paid()->create(['period' => '2026-09-01', 'due_date' => '2026-09-10']);

    $this->actingAs($member->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('dues_banner', false));
});

it('el manager nombra y quita admins, pero nunca se toca a sí mismo', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($manager->user)
        ->post("/plantel/{$player->id}/rol", ['role' => 'manager'])
        ->assertRedirect();

    expect($player->fresh()->isManager())->toBeTrue();

    // El nuevo admin puede crear eventos
    $this->actingAs($player->user)->post('/eventos', [
        'kind' => 'training',
        'starts_at' => now()->addDay()->format('Y-m-d H:i'),
        'venue' => 'Baza Sportivă',
    ])->assertRedirect(route('agenda'));

    // Y lo puede volver a bajar
    $this->actingAs($manager->user)
        ->post("/plantel/{$player->id}/rol", ['role' => 'player'])
        ->assertRedirect();
    expect($player->fresh()->isManager())->toBeFalse();

    // A sí mismo no, y un player tampoco puede tocar roles
    $this->actingAs($manager->user)->post("/plantel/{$manager->id}/rol", ['role' => 'player'])->assertForbidden();
    $this->actingAs($player->user)->post("/plantel/{$manager->id}/rol", ['role' => 'player'])->assertForbidden();
});

it('el plantel del perfil muestra el estado de cuota de cada uno', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create();

    $this->actingAs($manager->user)
        ->get('/perfil')
        ->assertInertia(fn (Assert $page) => $page
            ->where('roster.0.due_status', 'paid')
        );
});
