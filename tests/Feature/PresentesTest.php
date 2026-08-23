<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;

function eventoJugado(Member $creator, array $attrs = []): Event
{
    return Event::factory()->by($creator)->create([
        'starts_at' => now()->subHours(3),
        'notified_at' => now()->subDay(),
        ...$attrs,
    ]);
}

function dijoQueIba(Event $event, Member ...$members): void
{
    foreach ($members as $m) {
        Attendance::create(['event_id' => $event->id, 'member_id' => $m->id, 'status' => 'in', 'responded_at' => now()]);
    }
}

it('el manager confirma presentes: ajusta faltazos y colados', function () {
    $manager = Member::factory()->manager()->create();
    $fue = Member::factory()->for($manager->club)->create();
    $falto = Member::factory()->for($manager->club)->create();
    $colado = Member::factory()->for($manager->club)->create(); // no contestó pero fue
    $event = eventoJugado($manager);
    dijoQueIba($event, $manager, $fue, $falto);

    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/presentes", ['present_ids' => [$manager->id, $fue->id, $colado->id]])
        ->assertRedirect();

    $row = fn (Member $m) => Attendance::where('event_id', $event->id)->where('member_id', $m->id)->first();

    expect($row($fue)->attended)->toBeTrue()
        ->and($row($falto)->attended)->toBeFalse()
        ->and($row($falto)->status)->toBe('in') // lo que dijo no se pisa
        ->and($row($colado)->attended)->toBeTrue()
        ->and($row($colado)->status)->toBeNull()
        ->and($event->fresh()->attendance_confirmed_at)->not->toBeNull();
});

it('un player no puede confirmar presentes', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $event = eventoJugado($manager);

    $this->actingAs($player->user)
        ->post("/eventos/{$event->id}/presentes", ['present_ids' => [$player->id]])
        ->assertForbidden();
});

it('un manager de otro club no puede confirmar presentes ajenos', function () {
    $manager = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create(); // otro club
    $event = eventoJugado($manager);

    $this->actingAs($ajeno->user)
        ->post("/eventos/{$event->id}/presentes", ['present_ids' => []])
        ->assertNotFound();
});

it('no se pueden confirmar presentes antes de que termine el evento', function () {
    $manager = Member::factory()->manager()->create();
    $event = Event::factory()->by($manager)->create(['starts_at' => now()->addHour()]);

    $this->actingAs($manager->user)
        ->post("/eventos/{$event->id}/presentes", ['present_ids' => []])
        ->assertStatus(400);
});

it('la confirmación se puede corregir y no duplica filas', function () {
    $manager = Member::factory()->manager()->create();
    $a = Member::factory()->for($manager->club)->create();
    $event = eventoJugado($manager);
    dijoQueIba($event, $a);

    $this->actingAs($manager->user)->post("/eventos/{$event->id}/presentes", ['present_ids' => []]);
    $this->actingAs($manager->user)->post("/eventos/{$event->id}/presentes", ['present_ids' => [$a->id]]);

    $rows = Attendance::where('event_id', $event->id)->where('member_id', $a->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->attended)->toBeTrue();
});
