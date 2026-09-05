<?php

use App\Models\Event;
use App\Models\Member;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Notas del cuerpo técnico: un bloc compartido por evento que solo manager y
 * coach ven y editan. El jugador ni se entera de que existe: el prop no viaja.
 */

it('el coach guarda una nota y queda registrado quién editó', function () {
    $coach = Member::factory()->coach()->create();
    $event = Event::factory()->for($coach->club)->create([
        'created_by_member_id' => Member::factory()->manager()->for($coach->club)->create()->id,
    ]);

    $this->actingAs($coach->user)
        ->put("/eventos/{$event->id}/notas", ['body' => 'Presionar alto el segundo tiempo'])
        ->assertRedirect();

    $event->refresh();
    expect($event->staff_notes)->toBe('Presionar alto el segundo tiempo')
        ->and($event->staff_notes_updated_by_member_id)->toBe($coach->id)
        ->and($event->staff_notes_updated_at)->not->toBeNull();
});

it('el manager también edita el bloc', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create();

    $this->actingAs($manager->user)
        ->put("/eventos/{$event->id}/notas", ['body' => 'El 4 rindió de lateral'])
        ->assertRedirect();

    expect($event->fresh()->staff_notes)->toBe('El 4 rindió de lateral');
});

it('el jugador no puede escribir y su agenda ni incluye el campo', function () {
    $player = Member::factory()->create();
    $event = Event::factory()->for($player->club)->create([
        'created_by_member_id' => Member::factory()->manager()->for($player->club)->create()->id,
        'staff_notes' => 'Secreto del cuerpo técnico',
    ]);

    $this->actingAs($player->user)
        ->put("/eventos/{$event->id}/notas", ['body' => 'hackeo'])
        ->assertForbidden();

    $this->actingAs($player->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->missing('events.0.staff_notes'));
});

it('el staff ve la nota en la agenda, en próximos y en últimos partidos', function () {
    $coach = Member::factory()->coach()->create();
    $manager = Member::factory()->manager()->for($coach->club)->create();

    Event::factory()->by($manager)->create(['staff_notes' => 'Nota del próximo']);
    Event::factory()->by($manager)->create([
        'starts_at' => now()->subDays(3),
        'staff_notes' => 'Nota del jugado',
    ]);

    $this->actingAs($coach->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.0.staff_notes.body', 'Nota del próximo')
            ->where('recent.0.staff_notes.body', 'Nota del jugado')
        );
});

it('un evento de otro club da 404 aunque seas staff', function () {
    $coach = Member::factory()->coach()->create();
    $otroClub = Event::factory()->create(); // club ajeno

    $this->actingAs($coach->user)
        ->put("/eventos/{$otroClub->id}/notas", ['body' => 'x'])
        ->assertNotFound();
});

it('el dueño en modo "ver como jugador" no ve el bloc pero conserva el permiso real', function () {
    config(['app.owner_phone' => '+40712345678']);
    $owner = Member::factory()->manager()->create();
    $owner->user->update(['phone' => '+40712345678']);
    $event = Event::factory()->by($owner)->create(['staff_notes' => 'Reservado']);

    $this->actingAs($owner->user)->post('/ver-como'); // pasa a vista jugador

    $this->actingAs($owner->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->missing('events.0.staff_notes'));

    // La autorización server-side usa el rol real: puede guardar igual
    $this->actingAs($owner->user)
        ->put("/eventos/{$event->id}/notas", ['body' => 'sigue pudiendo'])
        ->assertRedirect();

    expect($event->fresh()->staff_notes)->toBe('sigue pudiendo');
});
