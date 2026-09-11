<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MvpVote;
use Inertia\Testing\AssertableInertia as Assert;

function partidoJugado(Member $creator, array $attrs = []): Event
{
    return Event::factory()->by($creator)->create([
        'starts_at' => now()->subDays(3),
        'notified_at' => now()->subDays(4),
        ...$attrs,
    ]);
}

it('la tabla trae el ranking del plantel: goles, asistencias, figuras y presencia', function () {
    $manager = Member::factory()->manager()->create(['joined_at' => now()->subYear()]);
    $crack = Member::factory()->for($manager->club)->create(['joined_at' => now()->subYear()]);

    // Tres partidos confirmados: el crack jugó todos y metió 3; el manager, 2 de 3
    foreach ([[2, 10], [1, 7], [0, 5]] as [$goles, $haceDias]) {
        $e = partidoJugado($manager, [
            'starts_at' => now()->subDays($haceDias),
            'attendance_confirmed_at' => now()->subDays($haceDias - 1),
            'goals_for' => 3, 'goals_against' => 1,
        ]);
        Attendance::create(['event_id' => $e->id, 'member_id' => $crack->id, 'status' => 'in', 'attended' => true, 'goals' => $goles, 'assists' => 1]);
        Attendance::create(['event_id' => $e->id, 'member_id' => $manager->id, 'status' => 'in', 'attended' => $haceDias !== 7]);
    }

    // Figura en el último partido: 2 votos al crack
    $ultimo = Event::query()->where('kind', 'match')->orderByDesc('starts_at')->first();
    $ultimo->update(['mvp_opened_at' => now()->subDays(5), 'mvp_closed_at' => now()->subDays(4)]);
    MvpVote::create(['event_id' => $ultimo->id, 'voter_member_id' => $manager->id, 'voted_member_id' => $crack->id]);
    MvpVote::create(['event_id' => $ultimo->id, 'voter_member_id' => $crack->id, 'voted_member_id' => $manager->id]);

    // Lo ve cualquier jugador del plantel, no solo el manager
    $this->actingAs($crack->user)
        ->get('/tabla')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ranking.goals.0.id', $crack->id)
            ->where('ranking.goals.0.value', 3)
            ->has('ranking.goals', 1) // el manager con 0 goles no aparece
            ->where('ranking.assists.0.value', 3)
            ->where('ranking.attendance.0.id', $crack->id)
            ->where('ranking.attendance.0.value', 100)
            ->where('ranking.attendance.1.value', 67)
        );
});

it('con menos de 3 partidos posibles no se rankea presencia', function () {
    $manager = Member::factory()->manager()->create(['joined_at' => now()->subYear()]);
    $nuevo = Member::factory()->for($manager->club)->create(['joined_at' => now()->subDays(2)]);

    $e = partidoJugado($manager, ['starts_at' => now()->subDay(), 'attendance_confirmed_at' => now()]);
    Attendance::create(['event_id' => $e->id, 'member_id' => $nuevo->id, 'status' => 'in', 'attended' => true]);

    $this->actingAs($nuevo->user)
        ->get('/tabla')
        ->assertInertia(fn (Assert $page) => $page->has('ranking.attendance', 0));
});

it('el ranking no mezcla clubes', function () {
    $manager = Member::factory()->manager()->create(['joined_at' => now()->subYear()]);
    $ajeno = Member::factory()->manager()->create(['joined_at' => now()->subYear()]); // otro club

    $e = partidoJugado($ajeno, ['attendance_confirmed_at' => now(), 'goals_for' => 5, 'goals_against' => 0]);
    Attendance::create(['event_id' => $e->id, 'member_id' => $ajeno->id, 'status' => 'in', 'attended' => true, 'goals' => 5]);

    $this->actingAs($manager->user)
        ->get('/tabla')
        ->assertInertia(fn (Assert $page) => $page->has('ranking.goals', 0));
});
