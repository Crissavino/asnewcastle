<?php

use App\Models\Attendance;
use App\Models\DeviceToken;
use App\Models\Event;
use App\Models\Member;
use App\Services\Push\PushSender;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Support\Carbon;
use Tests\Support\FakePushSender;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    config(['services.twilio.token' => 'test-token-secreto']);
    $this->whatsapp = new FakeWhatsAppChannel;
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Un evento del sábado 18:00 creado con una semana de anticipación. */
function eventoDelSabado(Member $member, array $attributes = []): Event
{
    return Event::factory()->by($member)->create([
        'starts_at' => Carbon::parse('2026-10-24 18:00'),
        'created_at' => Carbon::parse('2026-10-17 10:00'),
        ...$attributes,
    ]);
}

it('la lista cierra a las 22:00 del día anterior', function () {
    $member = Member::factory()->create();
    $event = eventoDelSabado($member);

    expect($event->rsvpClosesAt()->toDateTimeString())->toBe('2026-10-23 22:00:00');

    Carbon::setTestNow('2026-10-23 21:59');
    expect($event->rsvpOpen())->toBeTrue();

    Carbon::setTestNow('2026-10-23 22:00');
    expect($event->rsvpOpen())->toBeFalse();
});

it('el evento creado pasado el corte abre una ventana hasta 3hs antes', function () {
    $member = Member::factory()->create();

    // Se carga el viernes a las 23:30 un partido del sábado 18:00
    $event = eventoDelSabado($member, ['created_at' => Carbon::parse('2026-10-23 23:30')]);

    expect($event->rsvpClosesAt()->toDateTimeString())->toBe('2026-10-24 15:00:00');
});

it('el evento armado sobre la hora deja al menos una hora para contestar', function () {
    $member = Member::factory()->create();

    // Amistoso cargado a las 16:00 para las 18:00: 3hs antes ya pasó
    $event = eventoDelSabado($member, ['created_at' => Carbon::parse('2026-10-24 16:00')]);

    expect($event->rsvpClosesAt()->toDateTimeString())->toBe('2026-10-24 17:00:00');
});

it('el cierre nunca cae después del inicio del evento', function () {
    $member = Member::factory()->create();

    // Cargado 20 minutos antes: el piso de una hora no puede pasar el inicio
    $event = eventoDelSabado($member, ['created_at' => Carbon::parse('2026-10-24 17:40')]);

    expect($event->rsvpClosesAt()->toDateTimeString())->toBe('2026-10-24 18:00:00');
});

it('el jugador no puede cambiar su respuesta pasado el cierre', function () {
    $member = Member::factory()->create();
    $event = eventoDelSabado($member);
    Attendance::create(['event_id' => $event->id, 'member_id' => $member->id, 'status' => 'in', 'responded_at' => now(), 'source' => 'app']);

    Carbon::setTestNow('2026-10-24 09:00');

    $this->actingAs($member->user)
        ->post(route('asistencia', $event), ['status' => 'out'])
        ->assertForbidden();

    expect($event->attendances()->first()->status)->toBe('in');
});

it('el que nunca contestó tampoco puede anotarse pasado el cierre', function () {
    $member = Member::factory()->create();
    $event = eventoDelSabado($member);

    Carbon::setTestNow('2026-10-23 22:30');

    $this->actingAs($member->user)
        ->post(route('asistencia', $event), ['status' => 'in'])
        ->assertForbidden();

    expect($event->attendances()->count())->toBe(0);
});

it('antes del cierre el jugador responde normalmente', function () {
    $member = Member::factory()->create();
    $event = eventoDelSabado($member);

    Carbon::setTestNow('2026-10-23 21:00');

    $this->actingAs($member->user)
        ->post(route('asistencia', $event), ['status' => 'in'])
        ->assertRedirect();

    expect($event->attendances()->first()->status)->toBe('in');
});

it('el manager y el coach quedan exentos del cierre para su propia asistencia', function () {
    $manager = Member::factory()->manager()->create();
    $coach = Member::factory()->coach()->for($manager->club)->create();
    $event = eventoDelSabado($manager);

    Carbon::setTestNow('2026-10-24 09:00');

    $this->actingAs($manager->user)
        ->post(route('asistencia', $event), ['status' => 'in'])
        ->assertRedirect();

    $this->actingAs($coach->user)
        ->post(route('asistencia', $event), ['status' => 'in'])
        ->assertRedirect();

    expect($event->attendances()->count())->toBe(2);
});

it('el dueño viendo como jugador sigue pudiendo responder: la autorización usa el rol real', function () {
    $manager = Member::factory()->manager()->create();
    config(['app.owner_phone' => $manager->user->phone]);
    $event = eventoDelSabado($manager);

    Carbon::setTestNow('2026-10-24 09:00');

    $this->actingAs($manager->user)->withSession(['view_as_player' => true])
        ->post(route('asistencia', $event), ['status' => 'in'])
        ->assertRedirect();
});

it('el botón del WhatsApp pasado el cierre no graba y avisa que la lista cerró', function () {
    $member = Member::factory()->create();
    $member->user->update(['locale' => 'es']);
    $event = eventoDelSabado($member);

    Carbon::setTestNow('2026-10-24 09:00');

    twilioPost($this, [
        'MessageSid' => 'SM-cierre',
        'ButtonPayload' => "att:{$event->id}:in",
        'From' => 'whatsapp:'.$member->user->phone,
    ])->assertOk();

    expect($event->attendances()->count())->toBe(0)
        ->and($this->whatsapp->texts)->toHaveCount(1)
        ->and($this->whatsapp->texts[0]['body'])->toContain('cerr');
});

it('la agenda le manda al front cuándo cierra la lista y si sigue abierta', function () {
    $member = Member::factory()->create();
    eventoDelSabado($member);

    Carbon::setTestNow('2026-10-24 09:00');

    $this->actingAs($member->user)->get('/agenda')
        ->assertInertia(fn ($page) => $page
            ->where('events.0.rsvp_open', false)
            ->where('events.0.rsvp_closes_at', Carbon::parse('2026-10-23 22:00')->toIso8601String()));
});

it('el recordatorio automático no sale si la lista ya cerró', function () {
    $member = Member::factory()->create();

    // Cargado el sábado a las 08:00 para las 18:00: cerró a las 15:00
    $event = eventoDelSabado($member, ['created_at' => Carbon::parse('2026-10-24 08:00')]);
    $event->forceFill(['notified_at' => Carbon::parse('2026-10-24 08:00')])->save();

    Carbon::setTestNow('2026-10-24 16:00');
    $this->artisan('eventos:recordar')->assertSuccessful();

    expect($event->fresh()->reminded_at)->toBeNull()
        ->and($this->whatsapp->templates)->toBeEmpty();
});

it('el recordatorio de push avisa a qué hora cierra la lista', function () {
    $push = new FakePushSender;
    $this->app->instance(PushSender::class, $push);

    $member = Member::factory()->create();
    $member->user->update(['locale' => 'es']);
    DeviceToken::create(['user_id' => $member->user->id, 'token' => 'tok-1', 'platform' => 'ios']);

    $event = eventoDelSabado($member);
    $event->forceFill(['notified_at' => Carbon::parse('2026-10-17 10:00')])->save();

    Carbon::setTestNow('2026-10-23 18:00');
    $this->artisan('eventos:recordar')->assertSuccessful();

    expect($push->sent)->toHaveCount(1)
        ->and($push->sent[0]['body'])->toContain('la lista cierra hoy 22:00');
});
