<?php

use App\Models\Due;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Message;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->where('resumen.balance_cents', 12000)
            ->has('caja')
            ->missing('config')
            ->missing('gastos')
        );
});

it('el saldo descuenta los gastos, y el jugador ve el desglose por categoría', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->paid()->create(['amount_cents' => 12000]);

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'referee',
        'amount_cents' => 15000,
        'spent_on' => now()->toDateString(),
    ])->assertRedirect();

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'water',
        'amount_cents' => 4000,
        'spent_on' => now()->toDateString(),
        'description' => 'Bidones',
    ])->assertRedirect();

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where('resumen.balance_cents', 12000 - 19000) // puede quedar en rojo
            ->where('resumen.month_out_cents', 19000)
            ->where('resumen.by_category.referee', 15000)
            ->has('gastos', 2)
        );
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
        'event_id' => $event->id,
    ])->assertRedirect();

    $this->actingAs($manager->user)->post('/gastos', [
        'category' => 'referee',
        'amount_cents' => 15000,
        'spent_on' => now()->toDateString(),
        'event_id' => $ajeno->id,
    ])->assertNotFound();
});

it('el día del vencimiento se publica en el vestuario quiénes deben', function () {
    $manager = Member::factory()->manager()->create();
    $manager->user->update(['name' => 'Cristian Savino']);
    $deudor = Member::factory()->for($manager->club)->create();
    $deudor->user->update(['name' => 'Fabián Rodríguez']);

    Due::factory()->forMember($manager)->paid()->create(['due_date' => today()]);
    Due::factory()->forMember($deudor)->create(['due_date' => today()]);

    $this->artisan('cuotas:avisar')->assertSuccessful();

    $message = Message::withoutGlobalScopes()->where('is_system', true)->first();
    $body = json_decode($message->body, true);

    expect($body['key'])->toBe('system.dues_due')
        ->and($body['params']['names'])->toBe('Fabián')
        ->and($body['params']['names'])->not->toContain('Cristian');
});

it('sin vencimientos hoy, el aviso no publica nada', function () {
    $manager = Member::factory()->manager()->create();
    Due::factory()->forMember($manager)->create(['due_date' => today()->addDays(5)]);

    $this->artisan('cuotas:avisar')->assertSuccessful();

    expect(Message::withoutGlobalScopes()->count())->toBe(0);
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
