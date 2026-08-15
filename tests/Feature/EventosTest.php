<?php

use App\Models\Attendance;
use App\Models\Club;
use App\Models\Event;
use App\Models\Member;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->whatsapp = new FakeWhatsAppChannel();
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

it('el manager crea un evento y la convocatoria sale a todo el plantel', function () {
    $manager = Member::factory()->manager()->create();
    $players = Member::factory()->for($manager->club)->count(3)->create();

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'CS Afumați II',
        'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
        'kit' => 'home',
    ])->assertRedirect(route('agenda'));

    $event = Event::withoutGlobalScopes()->first();
    expect($event->opponent)->toBe('CS Afumați II')
        ->and($event->notified_at)->not->toBeNull()
        ->and($this->whatsapp->templates)->toHaveCount(4); // manager + 3 jugadores

    // El payload de los botones apunta al evento
    expect($this->whatsapp->templates[0]['variables']['5'])->toBe("att:{$event->id}:in");
});

it('un jugador no puede crear eventos', function () {
    $player = Member::factory()->create();

    $this->actingAs($player->user)->post('/eventos', [
        'kind' => 'training',
        'starts_at' => now()->addDay()->format('Y-m-d H:i'),
        'venue' => 'Teren',
        'kit' => 'home',
    ])->assertForbidden();

    expect(Event::withoutGlobalScopes()->count())->toBe(0);
});

it('responde Voy y si cambia de opinión se actualiza la misma fila', function () {
    $member = Member::factory()->create();
    $event = Event::factory()->by($member)->create();

    $this->actingAs($member->user)->post("/eventos/{$event->id}/asistencia", ['status' => 'in']);
    $this->actingAs($member->user)->post("/eventos/{$event->id}/asistencia", ['status' => 'out']);

    $rows = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe('out')
        ->and($rows->first()->source)->toBe('app');
});

it('no se puede responder a un evento de otro club', function () {
    $member = Member::factory()->create();
    $ajeno = Event::factory()->create(); // otro club

    $this->actingAs($member->user)
        ->post("/eventos/{$ajeno->id}/asistencia", ['status' => 'in'])
        ->assertNotFound();
});

it('la agenda solo muestra eventos del club activo', function () {
    $member = Member::factory()->create();
    Event::factory()->by($member)->create();
    Event::factory()->count(2)->create(); // otros clubes

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->has('events', 1));
});

it('el manager ve la convocatoria en texto plano copiable', function () {
    $manager = Member::factory()->manager()->create(['shirt_number' => 5]);
    $manager->user->update(['name' => 'Sergio Quiroga']);
    $event = Event::factory()->by($manager)->create();

    $p10 = Member::factory()->for($manager->club)->create(['shirt_number' => 10]);
    $p10->user->update(['name' => 'Cristian Savino']);

    Attendance::create(['event_id' => $event->id, 'member_id' => $p10->id, 'status' => 'in', 'responded_at' => now()]);
    Attendance::create(['event_id' => $event->id, 'member_id' => $manager->id, 'status' => 'in', 'responded_at' => now()]);

    $this->actingAs($manager->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.0.convocation', '5 Sergio Quiroga · 10 Cristian Savino')
        );
});

it('el recordatorio manual va solo a los que no contestaron', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create();
    $respondio = Member::factory()->for($manager->club)->create();
    $callado = Member::factory()->for($manager->club)->create();

    Attendance::create(['event_id' => $event->id, 'member_id' => $respondio->id, 'status' => 'in', 'responded_at' => now()]);

    $this->actingAs($manager->user)->post("/eventos/{$event->id}/recordar");

    $recipients = $this->whatsapp->templateRecipients();
    expect($recipients)->toContain($callado->user->phone)
        ->toContain($manager->user->phone)
        ->not->toContain($respondio->user->phone);
});

it('el job programado recuerda una sola vez, 24hs antes, a los que no contestaron', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create([
        'starts_at' => now()->addHours(20),
        'notified_at' => now()->subDay(),
    ]);
    $lejano = Event::factory()->by($manager)->create([
        'starts_at' => now()->addDays(5),
        'notified_at' => now()->subDay(),
    ]);

    Attendance::create(['event_id' => $event->id, 'member_id' => $manager->id, 'status' => 'in', 'responded_at' => now()]);
    $callado = Member::factory()->for($manager->club)->create();

    $this->artisan('eventos:recordar')->assertSuccessful();

    expect($this->whatsapp->templateRecipients())->toBe([$callado->user->phone])
        ->and($event->fresh()->reminded_at)->not->toBeNull()
        ->and($lejano->fresh()->reminded_at)->toBeNull();

    // Segunda corrida: no manda nada de nuevo
    $this->whatsapp->templates = [];
    $this->artisan('eventos:recordar')->assertSuccessful();
    expect($this->whatsapp->templates)->toBeEmpty();
});
