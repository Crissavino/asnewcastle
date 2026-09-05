<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->whatsapp = new FakeWhatsAppChannel();
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

it('el manager edita un evento y el cambio se avisa a todo el plantel', function () {
    $manager = Member::factory()->manager()->create();
    $otro = Member::factory()->for($manager->club)->create();
    $event = Event::factory()->by($manager)->create();

    $this->actingAs($manager->user)->put("/eventos/{$event->id}", [
        'kind' => 'match',
        'opponent' => 'CS Cernica',
        'is_home' => false,
        'starts_at' => now()->addDays(4)->format('Y-m-d H:i'),
        'venue' => 'Teren Cernica',
        'kit' => 'both',
    ])->assertRedirect(route('agenda'));

    $event->refresh();
    expect($event->opponent)->toBe('CS Cernica')
        ->and($event->kit)->toBe('both')
        ->and($event->is_home)->toBeFalse();

    // El aviso de cambio les llegó a los dos, con el título de CAMBIO
    expect($this->whatsapp->templates)->toHaveCount(2)
        ->and($this->whatsapp->templates[0]['variables']['1'])->toContain('🔁');

    $system = json_decode(Message::withoutGlobalScopes()->where('is_system', true)->latest('id')->first()->body, true);
    expect($system['key'])->toBe('system.event_updated_match');
});

it('un jugador no puede editar ni cancelar eventos', function () {
    $player = Member::factory()->create();
    $event = Event::factory()->by($player)->create();

    $this->actingAs($player->user)->put("/eventos/{$event->id}", [
        'kind' => 'training',
        'starts_at' => now()->addDay()->format('Y-m-d H:i'),
        'venue' => 'x',
    ])->assertForbidden();

    $this->actingAs($player->user)->post("/eventos/{$event->id}/cancelar")->assertForbidden();
});

it('cancelar avisa por WhatsApp, bloquea respuestas y no recuerda más', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create([
        'starts_at' => now()->addHours(20),
        'notified_at' => now()->subDay(),
    ]);

    $this->actingAs($manager->user)->post("/eventos/{$event->id}/cancelar")->assertRedirect();

    $event->refresh();
    expect($event->isCancelled())->toBeTrue()
        ->and($this->whatsapp->templates[0]['variables']['1'])->toContain('❌');

    // Cancelado dos veces no duplica avisos
    $count = count($this->whatsapp->templates);
    $this->actingAs($manager->user)->post("/eventos/{$event->id}/cancelar");
    expect($this->whatsapp->templates)->toHaveCount($count);

    // No se puede responder asistencia
    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/asistencia", ['status' => 'in'])
        ->assertStatus(400);

    // El recordatorio de 24hs lo saltea
    $this->whatsapp->templates = [];
    $this->artisan('eventos:recordar')->assertSuccessful();
    expect($this->whatsapp->templates)->toBeEmpty();
});

it('el webhook de WhatsApp ignora respuestas a eventos cancelados', function () {
    config(['services.twilio.token' => 'test-token-secreto']);
    $member = Member::factory()->create();
    $event = Event::factory()->by($member)->create(['cancelled_at' => now()]);

    $params = [
        'MessageSid' => 'SM950',
        'From' => 'whatsapp:'.$member->user->phone,
        'ButtonPayload' => "att:{$event->id}:in",
    ];
    ksort($params);
    $payload = 'http://localhost/webhooks/twilio';
    foreach ($params as $k => $v) {
        $payload .= $k.$v;
    }
    $sig = base64_encode(hash_hmac('sha1', $payload, 'test-token-secreto', true));

    $this->withHeaders(['X-Twilio-Signature' => $sig])->post('/webhooks/twilio', $params)->assertOk();

    expect(Attendance::where('event_id', $event->id)->count())->toBe(0);
});

it('el manager carga el resultado de un partido terminado y queda en la agenda', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create(['starts_at' => now()->subHours(4)]);

    // Antes de terminar no se puede
    $futuro = Event::factory()->by($manager)->create(['starts_at' => now()->addDay()]);
    $this->actingAs($manager->user)
        ->post("/eventos/{$futuro->id}/resultado", ['goals_for' => 1, 'goals_against' => 0])
        ->assertStatus(400);

    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/resultado", ['goals_for' => 3, 'goals_against' => 1])
        ->assertRedirect();

    expect($event->fresh()->hasResult())->toBeTrue();

    $system = json_decode(Message::withoutGlobalScopes()->where('is_system', true)->latest('id')->first()->body, true);
    expect($system['key'])->toBe('system.result')
        ->and($system['params']['gf'])->toBe(3);

    $this->actingAs($manager->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('recent.0.result.gf', 3)
            ->where('recent.0.result.ga', 1)
        );
});

it('un jugador no puede cargar resultados', function () {
    $player = Member::factory()->create();
    $event = Event::factory()->by($player)->create(['starts_at' => now()->subHours(4)]);

    $this->actingAs($player->user)
        ->post("/eventos/{$event->id}/resultado", ['goals_for' => 1, 'goals_against' => 0])
        ->assertForbidden();
});

it('todos ven quiénes van, dudan y no van, con nombres', function () {
    $member = Member::factory()->create();
    $event = Event::factory()->by($member)->create();
    $otro = Member::factory()->for($member->club)->create();

    Attendance::create(['event_id' => $event->id, 'member_id' => $otro->id, 'status' => 'out', 'responded_at' => now()]);

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.0.out', 1)
            ->where('events.0.out.0.name', $otro->user->name)
        );
});

it('una edición cosmética (kick-off, link, notas) no molesta a nadie', function () {
    $manager = Member::factory()->manager()->create();
    Member::factory()->for($manager->club)->create();
    $event = Event::factory()->by($manager)->create(['notes' => 'Kick of 11:00 - https://maps.app.goo.gl/xyz']);

    $this->actingAs($manager->user)->put("/eventos/{$event->id}", [
        'kind' => 'match',
        'opponent' => $event->opponent,
        'is_home' => $event->is_home,
        'starts_at' => $event->starts_at->format('Y-m-d H:i'),
        'kickoff_time' => '11:00',
        'venue' => $event->venue,
        'venue_url' => 'https://maps.app.goo.gl/xyz',
        'kit' => $event->kit,
        'notes' => null,
    ])->assertRedirect(route('agenda'));

    $event->refresh();
    expect($event->kickoff_at)->not->toBeNull()
        ->and($event->notes)->toBeNull()
        // Ni WhatsApp ni mensaje del sistema: nadie se enteró
        ->and($this->whatsapp->templates)->toHaveCount(0)
        ->and(Message::withoutGlobalScopes()->where('is_system', true)->count())->toBe(0);
});
