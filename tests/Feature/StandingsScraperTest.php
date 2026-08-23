<?php

use App\Models\Club;
use App\Models\Member;
use App\Services\StandingsScraper;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const CLASAMENT_URL = 'https://www.frf-ajf.ro/ilfov/competitii-fotbal/liga-a-5-a-15248/clasament';

function fixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/clasament.html'));
}

it('parsea el clasament de la AJF: posiciones, diferencia de gol y puntos', function () {
    $rows = app(StandingsScraper::class)->parse(fixtureHtml(), 'A.S New Castle');

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['pos' => 1, 'team' => 'ACS PERIS', 'pj' => 26, 'dg' => 67, 'pts' => 68, 'us' => false])
        ->and($rows[2]['team'])->toBe('AS NEW CASTLE')
        ->and($rows[2]['dg'])->toBe(23)
        ->and($rows[2]['pts'])->toBe(49)
        ->and($rows[2]['us'])->toBeTrue()
        ->and($rows[3]['team'])->toBe('AS DASCĂLU');
});

it('el comando importa la tabla y la deja en standings_json', function () {
    Http::fake([CLASAMENT_URL => Http::response(fixtureHtml())]);

    $club = Club::factory()->create([
        'name' => 'A.S New Castle',
        'standings_url' => CLASAMENT_URL,
    ]);
    Club::factory()->create(['standings_url' => null]); // sin URL: se saltea

    $this->artisan('tabla:importar')->assertSuccessful();

    Http::assertSentCount(1);

    $standings = $club->fresh()->standings_json;
    expect($standings)->toHaveCount(4)
        ->and(collect($standings)->firstWhere('us', true)['team'])->toBe('AS NEW CASTLE');
});

it('si la página cambió de formato no pisa la tabla que ya estaba', function () {
    // El clasament roto dispara el fallback a /echipe; si ese también viene
    // roto, no hay tabla nueva y NO se pisa la que ya estaba.
    Http::fake([
        CLASAMENT_URL => Http::response('<html><body>mantenimiento</body></html>'),
        str_replace('/clasament', '/echipe', CLASAMENT_URL) => Http::response('<html><body>mantenimiento</body></html>'),
    ]);

    $vieja = [['pos' => 1, 'team' => 'ACS PERIS', 'pj' => 2, 'dg' => 3, 'pts' => 6, 'us' => false]];
    $club = Club::factory()->create([
        'standings_url' => CLASAMENT_URL,
        'standings_json' => $vieja,
    ]);

    $this->artisan('tabla:importar')->assertFailed();

    expect($club->fresh()->standings_json)->toBe($vieja);
});

it('en pretemporada arma la tabla en 0 desde la lista de equipos (/echipe)', function () {
    $html = '<html><body>'
        .'<div class="name title_lista_echipe">1 DECEMBRIE</div>'
        .'<div class="name title_lista_echipe">AS NEW CASTLE</div>'
        .'<div class="name title_lista_echipe">CS DARASTI</div>'
        .'</body></html>';

    $rows = app(StandingsScraper::class)->parseEchipe($html, 'A.S New Castle');

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['pos' => 1, 'team' => '1 DECEMBRIE', 'pj' => 0, 'dg' => 0, 'pts' => 0, 'us' => false])
        ->and($rows[1]['team'])->toBe('AS NEW CASTLE')
        ->and($rows[1]['us'])->toBeTrue();
});

it('si el clasament está vacío, el comando arma la tabla desde /echipe', function () {
    $echipeUrl = 'https://www.frf-ajf.ro/ilfov/competitii-fotbal/liga-a-5-a-16633/echipe';
    $clasamentUrl = 'https://www.frf-ajf.ro/ilfov/competitii-fotbal/liga-a-5-a-16633/clasament';

    Http::fake([
        $clasamentUrl => Http::response('<html><body><table><tr><th>#</th><th>Echipa</th></tr></table></body></html>'),
        $echipeUrl => Http::response('<div class="name title_lista_echipe">1 DECEMBRIE</div><div class="name title_lista_echipe">AS NEW CASTLE</div>'),
    ]);

    $club = Club::factory()->create([
        'name' => 'A.S New Castle',
        'standings_url' => $clasamentUrl,
        'fixture_url' => null,
    ]);

    $this->artisan('tabla:importar')->assertSuccessful();

    $rows = $club->fresh()->standings_json;
    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->firstWhere('us', true)['team'])->toBe('AS NEW CASTLE');
});

it('la tabla importada se ve en la pestaña Tabla con el club resaltado', function () {
    $member = Member::factory()->create();
    $member->club->update(['standings_json' => [
        ['pos' => 1, 'team' => 'ACS PERIS', 'pj' => 26, 'dg' => 67, 'pts' => 68, 'us' => false],
        ['pos' => 2, 'team' => 'AS NEW CASTLE', 'pj' => 26, 'dg' => 23, 'pts' => 49, 'us' => true],
    ]]);

    $this->actingAs($member->user)
        ->get('/tabla')
        ->assertInertia(fn (Assert $page) => $page
            ->has('standings', 2)
            ->where('standings.1.us', true)
        );
});
