<?php

use App\Models\Event;
use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Itinerario del partido: hora de encuentro (starts_at), kick-off y link de la
 * cancha como campos de verdad — chau link de Maps pegado en las notas.
 */

it('el manager crea un partido con kick-off y link de cancha, y la agenda los muestra', function () {
    $manager = Member::factory()->manager()->create();
    $day = now()->addDays(3);

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'Moara Vlasiei',
        'is_home' => false,
        'starts_at' => $day->format('Y-m-d').' 09:30',
        'kickoff_time' => '11:00',
        'venue' => 'Teren Moară Vlăsiei',
        'venue_url' => 'https://maps.app.goo.gl/iFc2sSQWJFu643RD9',
        'kit' => 'away',
    ])->assertRedirect(route('agenda'));

    $event = Event::withoutGlobalScopes()->first();
    expect($event->kickoff_at->format('Y-m-d H:i'))->toBe($day->format('Y-m-d').' 11:00')
        ->and($event->venue_url)->toBe('https://maps.app.goo.gl/iFc2sSQWJFu643RD9');

    $this->actingAs($manager->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.0.kickoff_at', fn ($v) => str_contains($v, '11:00'))
            ->where('events.0.venue_url', 'https://maps.app.goo.gl/iFc2sSQWJFu643RD9')
        );
});

it('sin kick-off ni link todo sigue funcionando como antes', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'CS Cernica',
        'is_home' => true,
        'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
        'kit' => 'home',
    ])->assertRedirect(route('agenda'));

    $this->actingAs($manager->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.0.kickoff_at', null)
            ->where('events.0.venue_url', null)
        );
});

it('el kick-off no puede ser antes de la hora de encuentro', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'CS Cernica',
        'is_home' => true,
        'starts_at' => now()->addDays(3)->format('Y-m-d').' 11:00',
        'kickoff_time' => '09:30',
        'venue' => 'Teren Voluntari',
        'kit' => 'home',
    ])->assertSessionHasErrors('kickoff_time');
});

it('el link de la cancha tiene que ser una URL de verdad', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'CS Cernica',
        'is_home' => true,
        'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
        'venue_url' => 'esto no es un link',
        'kit' => 'home',
    ])->assertSessionHasErrors('venue_url');
});

it('con kick-off cargado, el partido termina 2hs después del kick-off, no del encuentro', function () {
    $conKickoff = Event::factory()->create([
        'starts_at' => now()->subHours(3),
        'kickoff_at' => now()->subHour(),
    ]);
    $sinKickoff = Event::factory()->create([
        'starts_at' => now()->subHours(3),
    ]);

    expect($conKickoff->isFinished())->toBeFalse()
        ->and($sinKickoff->isFinished())->toBeTrue();
});
