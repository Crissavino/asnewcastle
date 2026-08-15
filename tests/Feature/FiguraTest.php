<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Message;
use App\Models\MvpVote;
use App\Models\PlayerRating;
use Inertia\Testing\AssertableInertia as Assert;

function partidoTerminado(Member $creator): Event
{
    return Event::factory()->by($creator)->create([
        'starts_at' => now()->subHours(3),
        'notified_at' => now()->subDay(),
        'mvp_opened_at' => now()->subHour(),
    ]);
}

function fueron(Event $event, Member ...$members): void
{
    foreach ($members as $m) {
        Attendance::create(['event_id' => $event->id, 'member_id' => $m->id, 'status' => 'in', 'responded_at' => now()]);
    }
}

it('figura:abrir abre la votación una sola vez y avisa en el vestuario', function () {
    $manager = Member::factory()->manager()->create();
    $otro = Member::factory()->for($manager->club)->create();
    $event = Event::factory()->by($manager)->create([
        'starts_at' => now()->subHours(3),
        'notified_at' => now()->subDay(),
    ]);
    fueron($event, $manager, $otro);

    $entrenamiento = Event::factory()->by($manager)->training()->create(['starts_at' => now()->subHours(3)]);

    $this->artisan('figura:abrir')->assertSuccessful();
    $this->artisan('figura:abrir')->assertSuccessful();

    expect($event->fresh()->mvp_opened_at)->not->toBeNull()
        ->and($entrenamiento->fresh()->mvp_opened_at)->toBeNull()
        ->and(Message::withoutGlobalScopes()->where('is_system', true)->count())->toBe(1);
});

it('sin dos jugadores que hayan ido no se anuncia votación', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create(['starts_at' => now()->subHours(3)]);
    fueron($event, $manager);

    $this->artisan('figura:abrir')->assertSuccessful();

    expect($event->fresh()->mvp_opened_at)->not->toBeNull()
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('se vota a la figura y cambiar el voto no duplica', function () {
    $manager = Member::factory()->manager()->create();
    $a = Member::factory()->for($manager->club)->create();
    $b = Member::factory()->for($manager->club)->create();
    $event = partidoTerminado($manager);
    fueron($event, $manager, $a, $b);

    $this->actingAs($manager->user)->post("/eventos/{$event->id}/figura", ['member_id' => $a->id]);
    $this->actingAs($manager->user)->post("/eventos/{$event->id}/figura", ['member_id' => $b->id]);

    $votes = MvpVote::where('event_id', $event->id)->get();
    expect($votes)->toHaveCount(1)
        ->and($votes->first()->voted_member_id)->toBe($b->id);
});

it('no se puede votar a alguien que no fue al partido', function () {
    $manager = Member::factory()->manager()->create();
    $fue = Member::factory()->for($manager->club)->create();
    $noFue = Member::factory()->for($manager->club)->create();
    $event = partidoTerminado($manager);
    fueron($event, $manager, $fue);

    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/figura", ['member_id' => $noFue->id])
        ->assertSessionHasErrors('member_id');
});

it('la votación cierra a las 48hs del final', function () {
    $manager = Member::factory()->manager()->create();
    $a = Member::factory()->for($manager->club)->create();
    $event = Event::factory()->by($manager)->create([
        'starts_at' => now()->subHours(60),
        'mvp_opened_at' => now()->subHours(57),
    ]);
    fueron($event, $manager, $a);

    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/figura", ['member_id' => $a->id])
        ->assertStatus(400);
});

it('no se puede votar la figura de un partido de otro club', function () {
    $ajeno = Member::factory()->manager()->create();
    $event = partidoTerminado($ajeno);

    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->post("/eventos/{$event->id}/figura", ['member_id' => $ajeno->id])
        ->assertNotFound();
});

it('la calificación ternaria se guarda, se cambia y no permite autobombo', function () {
    $manager = Member::factory()->manager()->create();
    $a = Member::factory()->for($manager->club)->create();
    $event = partidoTerminado($manager);
    fueron($event, $manager, $a);

    // A uno mismo no
    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/puntaje", ['member_id' => $manager->id, 'rating' => 3])
        ->assertForbidden();

    $this->actingAs($manager->user)->post("/eventos/{$event->id}/puntaje", ['member_id' => $a->id, 'rating' => 1]);
    $this->actingAs($manager->user)->post("/eventos/{$event->id}/puntaje", ['member_id' => $a->id, 'rating' => 3]);

    $ratings = PlayerRating::where('event_id', $event->id)->get();
    expect($ratings)->toHaveCount(1)
        ->and($ratings->first()->rating)->toBe(3);

    // Fuera del 1..3 no pasa
    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/puntaje", ['member_id' => $a->id, 'rating' => 10])
        ->assertSessionHasErrors('rating');
});

it('el vestuario muestra la encuesta con totales anónimos', function () {
    $manager = Member::factory()->manager()->create();
    $a = Member::factory()->for($manager->club)->create();
    $event = partidoTerminado($manager);
    fueron($event, $manager, $a);

    MvpVote::create(['event_id' => $event->id, 'voter_member_id' => $a->id, 'voted_member_id' => $manager->id]);
    PlayerRating::create(['event_id' => $event->id, 'rater_member_id' => $a->id, 'rated_member_id' => $manager->id, 'rating' => 3]);

    $this->actingAs($manager->user)
        ->get('/vestuario')
        ->assertInertia(fn (Assert $page) => $page
            ->where('mvp.event_id', $event->id)
            ->has('mvp.candidates', 2)
            ->where('mvp.candidates.0.id', $manager->id) // ordenado por votos
            ->where('mvp.candidates.0.votes', 1)
            ->where('mvp.candidates.0.ratings', [0, 0, 1])
            ->missing('mvp.candidates.0.rater_member_id')
        );
});
