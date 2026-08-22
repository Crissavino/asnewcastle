<?php

use App\Models\Event;
use App\Models\Member;
use App\Models\Notification;
use App\Services\Push\PushSender;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePushSender;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    // La convocatoria de un evento también sale por push y WhatsApp: los
    // fakeamos para no salir a la red.
    $this->app->instance(PushSender::class, new FakePushSender());
    $this->app->instance(WhatsAppChannel::class, new FakeWhatsAppChannel());
});

it('comparte a la página el contador de no-leídas del jugador', function () {
    $member = Member::factory()->create();
    Notification::factory()->count(2)->create(['club_id' => $member->club_id, 'member_id' => $member->id]);
    Notification::factory()->read()->create(['club_id' => $member->club_id, 'member_id' => $member->id]);

    $this->actingAs($member->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread', 2)
            ->has('notifications.items', 3)
        );
});

it('marcar leídas pone read_at y el contador vuelve a cero', function () {
    $member = Member::factory()->create();
    Notification::factory()->count(3)->create(['club_id' => $member->club_id, 'member_id' => $member->id]);

    $this->actingAs($member->user)->post('/notificaciones/leidas')->assertRedirect();

    expect(Notification::where('member_id', $member->id)->whereNull('read_at')->count())->toBe(0);

    $this->actingAs($member->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 0));
});

it('un jugador no ve las notificaciones de otro club', function () {
    $me = Member::factory()->create();
    $otro = Member::factory()->create(); // otro club, otro member

    Notification::factory()->create(['club_id' => $otro->club_id, 'member_id' => $otro->id]);

    $this->actingAs($me->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread', 0)
            ->has('notifications.items', 0)
        );
});

it('tampoco ve las de un compañero del mismo club', function () {
    $me = Member::factory()->manager()->create();
    $companero = Member::factory()->for($me->club)->create();

    Notification::factory()->create(['club_id' => $me->club_id, 'member_id' => $companero->id]);

    $this->actingAs($me->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 0));
});

it('al crear un evento notifica a todo el plantel menos al autor', function () {
    $manager = Member::factory()->manager()->create();
    $p1 = Member::factory()->for($manager->club)->create();
    $p2 = Member::factory()->for($manager->club)->create();

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'CS Cernica',
        'is_home' => true,
        'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
        'kit' => 'home',
    ])->assertRedirect();

    $notified = Notification::where('type', 'event')->pluck('member_id');

    expect($notified)->toContain($p1->id)->toContain($p2->id)->not->toContain($manager->id);
});

it('cuando un jugador confirma, el manager recibe una campanita (no el jugador)', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $event = Event::factory()->by($manager)->create();

    $this->actingAs($player->user)
        ->post("/eventos/{$event->id}/asistencia", ['status' => 'in'])
        ->assertRedirect();

    $attendance = Notification::where('type', 'attendance')->get();

    expect($attendance)->toHaveCount(1)
        ->and($attendance->first()->member_id)->toBe($manager->id);
});
