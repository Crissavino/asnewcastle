<?php

use App\Models\Attendance;
use App\Models\Club;
use App\Models\Event;
use App\Models\Member;
use App\Models\PlayerRating;
use App\Services\PredictionService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Pronóstico del partido: chances de ganar/empatar/perder calculadas al leer,
 * con la forma en el clasament, los confirmados, los entrenamientos, el
 * historial contra el rival y la racha. Regla de oro: todo "Voy" suma.
 */

/** Un club con clasament scrapeado: puntero, nosotros, Moara Vlăsiei y colero. */
function clubConTabla(): Club
{
    return Club::factory()->create([
        'name' => 'A.S New Castle',
        'standings_json' => [
            ['pos' => 1, 'team' => 'AS Puntero 2020', 'pj' => 10, 'dg' => 25, 'pts' => 28, 'us' => false],
            ['pos' => 5, 'team' => 'A.S New Castle', 'pj' => 10, 'dg' => 0, 'pts' => 15, 'us' => true],
            ['pos' => 6, 'team' => 'AS Moara Vlăsiei', 'pj' => 10, 'dg' => -2, 'pts' => 13, 'us' => false],
            ['pos' => 10, 'team' => 'CS Colero', 'pj' => 10, 'dg' => -25, 'pts' => 2, 'us' => false],
        ],
    ]);
}

function partidoFuturo(Club $club, array $attrs = []): Event
{
    $manager = Member::factory()->manager()->for($club)->create();

    return Event::factory()->for($club)->create([
        'created_by_member_id' => $manager->id,
        'opponent' => 'Moara Vlasiei',
        ...$attrs,
    ]);
}

/** N jugadores nuevos del club que confirman "Voy". */
function confirman(Event $event, int $n = 1): \Illuminate\Support\Collection
{
    return Member::factory()->count($n)->for($event->club)->create()
        ->each(fn ($m) => Attendance::create([
            'event_id' => $event->id,
            'member_id' => $m->id,
            'status' => 'in',
            'source' => 'app',
            'responded_at' => now(),
        ]));
}

function pronostico(Event $event, ?Member $viewer = null): ?array
{
    return app(PredictionService::class)->forEvent($event->fresh(), $viewer);
}

it('el pronóstico de un partido futuro suma 100 y trae las tres chances', function () {
    $event = partidoFuturo(clubConTabla());
    confirman($event, 8);

    $p = pronostico($event);

    expect($p)->not->toBeNull()
        ->and($p['win'] + $p['draw'] + $p['lose'])->toBe(100)
        ->and($p['win'])->toBeGreaterThan(0)
        ->and($p['draw'])->toBeGreaterThan(0)
        ->and($p['lose'])->toBeGreaterThan(0)
        ->and($p['confirmed'])->toBe(8)
        ->and($p['opponent_known'])->toBeTrue();
});

it('no hay pronóstico para entrenamientos, cancelados ni partidos ya jugados', function () {
    $club = clubConTabla();

    $training = partidoFuturo($club, ['kind' => 'training', 'opponent' => null]);
    $cancelled = partidoFuturo($club, ['cancelled_at' => now()]);
    $played = partidoFuturo($club, ['starts_at' => now()->subDays(2)]);

    expect(pronostico($training))->toBeNull()
        ->and(pronostico($cancelled))->toBeNull()
        ->and(pronostico($played))->toBeNull();
});

it('cada Voy suma: confirmar nunca baja las chances de ganar', function () {
    $event = partidoFuturo(clubConTabla());

    $previous = pronostico($event)['win'];
    $first = $previous;

    foreach (range(1, 8) as $i) {
        confirman($event);
        $current = pronostico($event)['win'];

        expect($current)->toBeGreaterThanOrEqual($previous, "el Voy nº{$i} bajó el pronóstico");
        $previous = $current;
    }

    // Ocho confirmados tienen que mover la aguja de verdad
    expect($previous)->toBeGreaterThan($first);
});

it('jugar de local da más chances que de visitante', function () {
    $club = clubConTabla();

    $home = partidoFuturo($club, ['is_home' => true]);
    $away = partidoFuturo($club, ['is_home' => false]);
    confirman($home, 8);
    confirman($away, 8);

    expect(pronostico($home)['win'])->toBeGreaterThan(pronostico($away)['win']);
});

it('un rival puntero achica las chances frente a un rival colero', function () {
    $club = clubConTabla();

    $vsPuntero = partidoFuturo($club, ['opponent' => 'AS Puntero 2020']);
    $vsColero = partidoFuturo($club, ['opponent' => 'CS Colero']);
    confirman($vsPuntero, 8);
    confirman($vsColero, 8);

    expect(pronostico($vsColero)['win'])->toBeGreaterThan(pronostico($vsPuntero)['win']);
});

it('haber perdido el cruce anterior baja el pronóstico (historial en la app)', function () {
    $ganamos = clubConTabla();
    $perdimos = clubConTabla();

    // Mismo cruce previo contra Moara, con resultado cargado por el delegado
    partidoFuturo($ganamos, ['starts_at' => now()->subWeeks(3), 'goals_for' => 3, 'goals_against' => 1]);
    partidoFuturo($perdimos, ['starts_at' => now()->subWeeks(3), 'goals_for' => 1, 'goals_against' => 3]);

    $eventoGanamos = partidoFuturo($ganamos);
    $eventoPerdimos = partidoFuturo($perdimos);
    confirman($eventoGanamos, 8);
    confirman($eventoPerdimos, 8);

    expect(pronostico($eventoGanamos)['win'])->toBeGreaterThan(pronostico($eventoPerdimos)['win']);
});

it('el historial cuenta aunque el cruce sea de la temporada pasada', function () {
    $conHistoria = clubConTabla();
    $sinHistoria = clubConTabla();

    // Liga 5, temporada pasada: nos ganaron 3-1 (cargado en la app)
    partidoFuturo($conHistoria, ['starts_at' => now()->subMonths(10), 'goals_for' => 1, 'goals_against' => 3]);

    $eventoConHistoria = partidoFuturo($conHistoria);
    $eventoSinHistoria = partidoFuturo($sinHistoria);
    confirman($eventoConHistoria, 8);
    confirman($eventoSinHistoria, 8);

    expect(pronostico($eventoConHistoria)['win'])->toBeLessThan(pronostico($eventoSinHistoria)['win']);
});

it('sin cruces en la app, el historial sale del fixture scrapeado', function () {
    $fixtureVs = fn (int $gf, int $ga) => [[
        'etapa' => 3,
        'date' => now()->subWeeks(4)->format('Y-m-d'),
        'opponent' => 'AS Moara Vlăsiei',
        'is_home' => true,
        'played' => true,
        'home_score' => $gf,
        'away_score' => $ga,
    ]];

    $ganamos = clubConTabla();
    $ganamos->update(['fixture_json' => $fixtureVs(3, 1)]);
    $perdimos = clubConTabla();
    $perdimos->update(['fixture_json' => $fixtureVs(1, 3)]);

    $eventoGanamos = partidoFuturo($ganamos);
    $eventoPerdimos = partidoFuturo($perdimos);
    confirman($eventoGanamos, 8);
    confirman($eventoPerdimos, 8);

    expect(pronostico($eventoGanamos)['win'])->toBeGreaterThan(pronostico($eventoPerdimos)['win']);
});

it('el cruce de la temporada pasada sale del historial importado de la web', function () {
    $historial = fn (int $gf, int $ga) => [[
        'etapa' => 12,
        'date' => now()->subMonths(10)->format('Y-m-d'),
        'opponent' => 'AS Moara Vlăsiei',
        'is_home' => false,
        'played' => true,
        'home_score' => $ga,
        'away_score' => $gf,
    ]];

    $conHistorial = clubConTabla();
    $conHistorial->update(['history_json' => $historial(1, 3)]);
    $sinHistorial = clubConTabla();

    $eventoConHistorial = partidoFuturo($conHistorial);
    $eventoSinHistorial = partidoFuturo($sinHistorial);
    confirman($eventoConHistorial, 8);
    confirman($eventoSinHistorial, 8);

    expect(pronostico($eventoConHistorial)['win'])->toBeLessThan(pronostico($eventoSinHistorial)['win']);
});

it('no cuenta dos veces el cruce que está en la app y también en el historial', function () {
    $cruce = now()->subMonths(3);
    $fila = fn (string $date, int $hs, int $as) => [
        'etapa' => 8, 'date' => $date, 'opponent' => 'AS Moara Vlăsiei',
        'is_home' => true, 'played' => true, 'home_score' => $hs, 'away_score' => $as,
    ];
    // Un cruce viejo perdido, igual en los dos clubes
    $viejo = $fila(now()->subMonths(14)->format('Y-m-d'), 0, 2);

    $duplicado = clubConTabla();
    $duplicado->update(['history_json' => [$fila($cruce->format('Y-m-d'), 3, 1), $viejo]]);
    $soloApp = clubConTabla();
    $soloApp->update(['history_json' => [$viejo]]);

    // El mismo partido reciente con resultado cargado en la app, en los dos clubes
    foreach ([$duplicado, $soloApp] as $club) {
        partidoFuturo($club, ['starts_at' => $cruce, 'goals_for' => 3, 'goals_against' => 1]);
    }

    $eventoDuplicado = partidoFuturo($duplicado);
    $eventoSoloApp = partidoFuturo($soloApp);
    confirman($eventoDuplicado, 8);
    confirman($eventoSoloApp, 8);

    expect(pronostico($eventoDuplicado)['win'])->toBe(pronostico($eventoSoloApp)['win']);
});

it('matchea al rival aunque el nombre venga con diacríticos o prefijo de club', function () {
    // En el clasament figura "AS Moara Vlăsiei"; el delegado tipeó "Moara Vlasiei"
    $event = partidoFuturo(clubConTabla(), ['opponent' => 'Moara Vlasiei']);

    expect(pronostico($event)['opponent_known'])->toBeTrue();
});

it('un rival que no está en el clasament no rompe el pronóstico', function () {
    // Copa: rival de otra liga, no figura en nuestro clasament
    $event = partidoFuturo(clubConTabla(), ['opponent' => 'CS Blejoi']);
    confirman($event, 8);

    $p = pronostico($event);

    expect($p['opponent_known'])->toBeFalse()
        ->and($p['win'] + $p['draw'] + $p['lose'])->toBe(100);
});

it('los entrenamientos recientes de los confirmados empujan el pronóstico', function () {
    $entrenados = clubConTabla();
    $vagos = clubConTabla();

    $eventoEntrenados = partidoFuturo($entrenados);
    $eventoVagos = partidoFuturo($vagos);
    $plantelEntrenado = confirman($eventoEntrenados, 8);
    confirman($eventoVagos, 8);

    // Dos entrenamientos en las últimas semanas: unos fueron a todo, otros a nada
    foreach ([$entrenados, $vagos] as $club) {
        Event::factory()->count(2)->for($club)->create([
            'kind' => 'training',
            'opponent' => null,
            'created_by_member_id' => Member::factory()->manager()->for($club)->create()->id,
            'starts_at' => now()->subWeek(),
        ]);
    }

    Event::withoutGlobalScopes()
        ->where('club_id', $entrenados->id)->where('kind', 'training')->get()
        ->each(fn ($training) => $plantelEntrenado->each(fn ($m) => Attendance::create([
            'event_id' => $training->id,
            'member_id' => $m->id,
            'status' => 'in',
            'source' => 'app',
        ])));

    expect(pronostico($eventoEntrenados)['win'])->toBeGreaterThan(pronostico($eventoVagos)['win']);
});

it('un plantel bien calificado suma más que uno sin historia', function () {
    $cracks = clubConTabla();
    $normales = clubConTabla();

    $eventoCracks = partidoFuturo($cracks);
    $eventoNormales = partidoFuturo($normales);
    $plantelCracks = confirman($eventoCracks, 8);
    confirman($eventoNormales, 8);

    // Los confirmados del primer club vienen calificados "crack" por sus compañeros
    $pasado = partidoFuturo($cracks, ['starts_at' => now()->subWeeks(2), 'goals_for' => null, 'goals_against' => null]);
    $rater = Member::factory()->for($cracks)->create();
    $plantelCracks->each(fn ($m) => PlayerRating::create([
        'event_id' => $pasado->id,
        'rater_member_id' => $rater->id,
        'rated_member_id' => $m->id,
        'rating' => PlayerRating::STAR,
    ]));

    expect(pronostico($eventoCracks)['win'])->toBeGreaterThan(pronostico($eventoNormales)['win']);
});

it('la racha reciente del equipo pesa en el pronóstico', function () {
    $fixtureConRacha = fn (int $gf, int $ga) => collect(range(1, 3))->map(fn ($i) => [
        'etapa' => $i,
        'date' => now()->subWeeks(6 - $i)->format('Y-m-d'),
        'opponent' => "AS Rival {$i}",
        'is_home' => true,
        'played' => true,
        'home_score' => $gf,
        'away_score' => $ga,
    ])->all();

    $enRacha = clubConTabla();
    $enRacha->update(['fixture_json' => $fixtureConRacha(2, 0)]);
    $enCaida = clubConTabla();
    $enCaida->update(['fixture_json' => $fixtureConRacha(0, 2)]);

    $eventoEnRacha = partidoFuturo($enRacha);
    $eventoEnCaida = partidoFuturo($enCaida);
    confirman($eventoEnRacha, 8);
    confirman($eventoEnCaida, 8);

    expect(pronostico($eventoEnRacha)['win'])->toBeGreaterThan(pronostico($eventoEnCaida)['win']);
});

it('el pronóstico usa solo los datos del club del evento', function () {
    $club = clubConTabla();
    $event = partidoFuturo($club);
    confirman($event, 8);

    $antes = pronostico($event);

    // Otro club perdió por paliza contra el mismo rival: no nos afecta
    $otro = clubConTabla();
    partidoFuturo($otro, ['starts_at' => now()->subWeeks(2), 'goals_for' => 0, 'goals_against' => 8]);
    confirman(partidoFuturo($otro), 3);

    expect(pronostico($event))->toBe($antes);
});

it('al que no confirmó le muestra cuánto subiría su Voy; al confirmado no', function () {
    $event = partidoFuturo(clubConTabla());
    confirman($event, 5);

    $sinConfirmar = Member::factory()->for($event->club)->create();
    $confirmado = confirman($event)->first();

    $p = pronostico($event, $sinConfirmar);
    expect($p['if_you_confirm'])->not->toBeNull()
        ->and($p['if_you_confirm'])->toBeGreaterThanOrEqual($p['win']);

    expect(pronostico($event, $confirmado)['if_you_confirm'])->toBeNull();
});

it('la agenda muestra el pronóstico del partido a todo el plantel', function () {
    $club = clubConTabla();
    $player = Member::factory()->for($club)->create();
    partidoFuturo($club);
    partidoFuturo($club, ['kind' => 'training', 'opponent' => null]);

    $this->actingAs($player->user)->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Agenda')
            ->where('events.0.prediction.win', fn ($win) => is_int($win) && $win > 0)
            ->where('events.1.prediction', null)
        );
});
