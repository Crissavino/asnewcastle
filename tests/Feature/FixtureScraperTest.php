<?php

use App\Models\Club;
use App\Models\Member;
use App\Services\FixtureScraper;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

const PROGRAM_URL = 'https://www.frf-ajf.ro/ilfov/competitii-fotbal/liga-a-5-a-16633/program';

function programHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/program.html'));
}

it('parsea el fixture: solo los partidos del club, con local/visitante y resultado', function () {
    $rows = app(FixtureScraper::class)->parse(programHtml(), 'A.S New Castle');

    // Se filtra el partido que no es del club (DARASTI - MOARA VLASIEI)
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe([
            'etapa' => 1, 'date' => '2026-09-13', 'opponent' => '1 DECEMBRIE',
            'is_home' => false, 'played' => true, 'home_score' => 2, 'away_score' => 3,
        ])
        ->and($rows[1]['opponent'])->toBe('CS GLORIA BURIAS')
        ->and($rows[1]['is_home'])->toBeTrue()
        ->and($rows[1]['played'])->toBeFalse()
        ->and($rows[2]['opponent'])->toBe('CS MILANETO')
        ->and($rows[2]['is_home'])->toBeFalse();
});

it('el comando importa el fixture y lo deja en fixture_json', function () {
    Http::fake([PROGRAM_URL => Http::response(programHtml())]);

    $club = Club::factory()->create([
        'name' => 'A.S New Castle',
        'standings_url' => null,
        'fixture_url' => PROGRAM_URL,
    ]);

    $this->artisan('tabla:importar')->assertSuccessful();

    Http::assertSentCount(1);

    expect($club->fresh()->fixture_json)->toHaveCount(3);
});

it('si el fixture vino vacío no pisa el que ya estaba', function () {
    Http::fake([PROGRAM_URL => Http::response('<html><body>mantenimiento</body></html>')]);

    $viejo = [['etapa' => 1, 'date' => '2026-09-13', 'opponent' => 'X', 'is_home' => true, 'played' => false, 'home_score' => null, 'away_score' => null]];
    $club = Club::factory()->create([
        'standings_url' => null,
        'fixture_url' => PROGRAM_URL,
        'fixture_json' => $viejo,
    ]);

    $this->artisan('tabla:importar')->assertFailed();

    expect($club->fresh()->fixture_json)->toBe($viejo);
});

it('el fixture se ve en la pestaña Tabla', function () {
    $member = Member::factory()->create();
    $member->club->update(['fixture_json' => [
        ['etapa' => 1, 'date' => '2026-09-13', 'opponent' => '1 DECEMBRIE', 'is_home' => false, 'played' => true, 'home_score' => 2, 'away_score' => 3],
        ['etapa' => 2, 'date' => '2026-09-20', 'opponent' => 'CS GLORIA BURIAS', 'is_home' => true, 'played' => false, 'home_score' => null, 'away_score' => null],
    ]]);

    $this->actingAs($member->user)
        ->get('/tabla')
        ->assertInertia(fn (Assert $page) => $page
            ->has('fixture', 2)
            ->where('fixture.0.opponent', '1 DECEMBRIE')
        );
});
