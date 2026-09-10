<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\MvpVote;
use App\Models\PlayerRating;
use Inertia\Testing\AssertableInertia as Assert;

function partidoDe(Member $creator, array $attrs = []): Event
{
    return Event::factory()->by($creator)->create([
        'starts_at' => now()->subDays(3),
        'notified_at' => now()->subDays(4),
        ...$attrs,
    ]);
}

function asistencia(Event $event, Member $m, ?string $status, ?bool $attended = null): void
{
    Attendance::create([
        'event_id' => $event->id,
        'member_id' => $m->id,
        'status' => $status,
        'responded_at' => $status ? now() : null,
        'attended' => $attended,
    ]);
}

it('las estadísticas propias salen de presentes, votos y calificaciones', function () {
    $manager = Member::factory()->manager()->create();
    $p = Member::factory()->for($manager->club)->create(['joined_at' => now()->subYear()]);

    // Partido 1 (confirmado): jugó, 2 "crack", figura con 2 votos
    $m1 = partidoDe($manager, ['starts_at' => now()->subDays(10), 'attendance_confirmed_at' => now()->subDays(9), 'mvp_opened_at' => now()->subDays(10), 'mvp_closed_at' => now()->subDays(8), 'goals_for' => 2, 'goals_against' => 1]);
    asistencia($m1, $p, 'in', true);
    asistencia($m1, $manager, 'in', true);
    PlayerRating::create(['event_id' => $m1->id, 'rater_member_id' => $manager->id, 'rated_member_id' => $p->id, 'rating' => 3]);
    $tercero = Member::factory()->for($manager->club)->create();
    PlayerRating::create(['event_id' => $m1->id, 'rater_member_id' => $tercero->id, 'rated_member_id' => $p->id, 'rating' => 3]);
    MvpVote::create(['event_id' => $m1->id, 'voter_member_id' => $manager->id, 'voted_member_id' => $p->id]);
    MvpVote::create(['event_id' => $m1->id, 'voter_member_id' => $tercero->id, 'voted_member_id' => $p->id]);

    // Partido 2 (confirmado): dijo "Voy" y faltó
    $m2 = partidoDe($manager, ['starts_at' => now()->subDays(6), 'attendance_confirmed_at' => now()->subDays(5)]);
    asistencia($m2, $p, 'in', false);

    // Partido 3 (sin confirmar): dijo "Voy" → cuenta como jugado
    $m3 = partidoDe($manager, ['starts_at' => now()->subDays(2)]);
    asistencia($m3, $p, 'in');

    // Entrenamiento (sin confirmar): fue
    $t1 = Event::factory()->by($manager)->training()->create(['starts_at' => now()->subDays(4)]);
    asistencia($t1, $p, 'in');

    $this->actingAs($p->user)
        ->get('/estadisticas')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estadisticas')
            ->where('stats.matches_played', 2)
            ->where('stats.matches_total', 3)
            ->where('stats.absences', 1)
            ->where('stats.trainings_attended', 1)
            ->where('stats.trainings_total', 1)
            ->where('stats.streak', 1)
            ->where('stats.mvps', 1)
            ->where('stats.mvp_votes', 2)
            ->where('stats.ratings', [0, 0, 2])
            ->where('stats.rating_avg', 3)
            ->has('stats.timeline', 3)
            ->where('stats.timeline.0.played', true)   // m3, el más reciente
            ->where('stats.timeline.1.played', false)  // m2: faltazo
            ->where('stats.timeline.2.mvp', true)      // m1
            ->where('stats.timeline.2.ratings', [0, 0, 2])
        );
});

it('el récord del equipo se parte entre partidos con él y sin él', function () {
    $manager = Member::factory()->manager()->create();
    $p = Member::factory()->for($manager->club)->create(['joined_at' => now()->subYear()]);

    // Jugó: victoria 3-1 y empate 1-1
    $m1 = partidoDe($manager, ['starts_at' => now()->subDays(20), 'attendance_confirmed_at' => now()->subDays(19), 'goals_for' => 3, 'goals_against' => 1]);
    asistencia($m1, $p, 'in', true);
    $m2 = partidoDe($manager, ['starts_at' => now()->subDays(15), 'attendance_confirmed_at' => now()->subDays(14), 'goals_for' => 1, 'goals_against' => 1]);
    asistencia($m2, $p, 'in', true);

    // No jugó: derrota 0-2
    $m3 = partidoDe($manager, ['starts_at' => now()->subDays(10), 'attendance_confirmed_at' => now()->subDays(9), 'goals_for' => 0, 'goals_against' => 2]);
    asistencia($m3, $p, 'out');

    // Sin resultado cargado: no cuenta para el récord
    $m4 = partidoDe($manager, ['starts_at' => now()->subDays(5), 'attendance_confirmed_at' => now()->subDays(4)]);
    asistencia($m4, $p, 'in', true);

    $this->actingAs($p->user)
        ->get('/estadisticas')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.team_record.with', ['played' => 2, 'won' => 1, 'drawn' => 1, 'lost' => 0, 'gf_avg' => 2, 'ga_avg' => 1])
            ->where('stats.team_record.without', ['played' => 1, 'won' => 0, 'drawn' => 0, 'lost' => 1, 'gf_avg' => 0, 'ga_avg' => 2])
        );
});

it('un player no ve las estadísticas de un compañero; el manager sí', function () {
    $manager = Member::factory()->manager()->create();
    $a = Member::factory()->for($manager->club)->create();
    $b = Member::factory()->for($manager->club)->create();

    $this->actingAs($a->user)->get("/plantel/{$b->id}/estadisticas")->assertForbidden();
    $this->actingAs($a->user)->get("/plantel/{$a->id}/estadisticas")->assertOk();
    $this->actingAs($manager->user)->get("/plantel/{$b->id}/estadisticas")->assertOk();
});

it('las estadísticas no cruzan clubes', function () {
    $manager = Member::factory()->manager()->create();
    $ajeno = Member::factory()->manager()->create();

    $this->actingAs($ajeno->user)->get("/plantel/{$manager->id}/estadisticas")->assertNotFound();
});

it('sin eventos la página no revienta', function () {
    $p = Member::factory()->create();

    $this->actingAs($p->user)
        ->get('/estadisticas')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Estadisticas')
            ->where('stats.matches_played', 0)
            ->where('stats.match_pct', null)
            ->where('stats.rating_avg', null)
            ->has('stats.timeline', 0)
        );
});
