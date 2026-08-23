<?php

namespace App\Services;

use App\Models\Club;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Scrapea el /program de frf-ajf (el fixture de la liga) y guarda SOLO los
 * partidos del club en clubs.fixture_json.
 *
 * La tabla del program viene como: Meciul | Etapa | Data | Scor | Detalii
 * donde "Meciul" es "LOCAL - VISITANTE" y "Scor" es "-" o "2 - 1".
 */
class FixtureScraper
{
    public function update(Club $club): array
    {
        if (! $club->fixture_url) {
            throw new RuntimeException("El club {$club->slug} no tiene fixture_url.");
        }

        $html = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NewCastleApp/1.0)'])
            ->timeout(30)
            ->get($club->fixture_url)
            ->throw()
            ->body();

        $rows = $this->parse($html, $club->name);

        if (empty($rows)) {
            throw new RuntimeException('El fixture vino vacío o cambió el formato de la página.');
        }

        $club->update(['fixture_json' => $rows]);

        return $rows;
    }

    /**
     * @return array<int, array{etapa: int, date: string, opponent: string, is_home: bool, played: bool, home_score: ?int, away_score: ?int}>
     */
    public function parse(string $html, string $clubName): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8'));
        libxml_clear_errors();

        $me = $this->normalize($clubName);
        $fixture = [];

        foreach ((new DOMXPath($doc))->query('//table//tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (in_array($cell->nodeName, ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
                }
            }

            // Fila de datos: al menos Meciul | Etapa | Data | Scor, con " - " en el cruce
            if (count($cells) < 4 || ! str_contains($cells[0], ' - ')) {
                continue;
            }

            [$home, $away] = array_pad(explode(' - ', $cells[0], 2), 2, '');

            // Solo los partidos del club
            $isHome = $this->normalize($home) === $me;
            $isAway = $this->normalize($away) === $me;

            if (! $isHome && ! $isAway) {
                continue;
            }

            // Fecha libre (vs el comodín "STA ACEASTA ETAPA"): no es un partido
            if ($this->isBye($isHome ? $away : $home)) {
                continue;
            }

            [$homeScore, $awayScore] = $this->parseScore($cells[3]);

            $fixture[] = [
                'etapa' => (int) $cells[1],
                'date' => $cells[2],
                'opponent' => trim($isHome ? $away : $home),
                'is_home' => $isHome,
                'played' => $homeScore !== null,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
            ];
        }

        return $fixture;
    }

    /** "2 - 1" → [2, 1]; "-" o vacío → [null, null]. */
    protected function parseScore(string $scor): array
    {
        if (preg_match('/(\d+)\s*[-–:]\s*(\d+)/u', $scor, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /** "A.S New Castle" y "AS NEW CASTLE" tienen que matchear. */
    protected function normalize(string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($name));
    }

    /** "STA ACEASTA ETAPA" = descansa esta fecha (comodín), no un partido real. */
    protected function isBye(string $team): bool
    {
        return str_contains($this->normalize($team), 'ACEASTAETAPA');
    }
}
