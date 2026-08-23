<?php

namespace App\Services;

use App\Models\Club;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Scrapea el clasament de frf-ajf.ro y lo convierte al formato de
 * clubs.standings_json: [{pos, team, pj, dg, pts, us}, ...].
 *
 * La tabla de la AJF viene como: # | Echipa | M | V | E | I | GM | GP | P
 * (partidos, victorias, empates, derrotas, goles a favor/en contra, puntos "68p").
 */
class StandingsScraper
{
    public function update(Club $club): array
    {
        if (! $club->standings_url) {
            throw new RuntimeException("El club {$club->slug} no tiene standings_url.");
        }

        $html = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NewCastleApp/1.0)'])
            ->timeout(30)
            ->get($club->standings_url)
            ->throw()
            ->body();

        $rows = $this->parse($html, $club->name);

        // Pretemporada: el clasament está vacío. Armamos la tabla con los
        // equipos participantes (todos en 0) para que se vea igual, y de paso
        // no falla el import diario hasta que se juegue la 1ª fecha.
        if (count($rows) < 2) {
            $rows = $this->fromEchipe($club);
        }

        if (count($rows) < 2) {
            throw new RuntimeException('El clasament vino vacío o cambió el formato de la página.');
        }

        $club->update(['standings_json' => $rows]);

        return $rows;
    }

    /** Tabla en 0 a partir de la lista de equipos participantes (/echipe). */
    protected function fromEchipe(Club $club): array
    {
        $url = str_replace('/clasament', '/echipe', $club->standings_url);

        if ($url === $club->standings_url) {
            return [];
        }

        try {
            $html = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NewCastleApp/1.0)'])
                ->timeout(30)
                ->get($url)
                ->throw()
                ->body();
        } catch (\Throwable) {
            return [];
        }

        return $this->parseEchipe($html, $club->name);
    }

    /** @return array<int, array{pos: int, team: string, pj: int, dg: int, pts: int, us: bool}> */
    public function parseEchipe(string $html, string $clubName): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8'));
        libxml_clear_errors();

        $rows = [];
        $pos = 0;

        foreach ((new DOMXPath($doc))->query("//*[contains(@class,'title_lista_echipe')]") as $node) {
            $team = trim(preg_replace('/\s+/u', ' ', $node->textContent));

            if ($team === '' || $this->isBye($team)) {
                continue;
            }

            $rows[] = [
                'pos' => ++$pos,
                'team' => $team,
                'pj' => 0,
                'dg' => 0,
                'pts' => 0,
                'us' => $this->normalize($team) === $this->normalize($clubName),
            ];
        }

        return $rows;
    }

    /** @return array<int, array{pos: int, team: string, pj: int, dg: int, pts: int, us: bool}> */
    public function parse(string $html, string $clubName): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8'));
        libxml_clear_errors();

        $standings = [];

        foreach ((new DOMXPath($doc))->query('//table//tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (in_array($cell->nodeName, ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
                }
            }

            // Solo filas de datos: 9 columnas y posición numérica
            if (count($cells) < 9 || ! ctype_digit($cells[0])) {
                continue;
            }

            $team = $cells[1];

            // "STA ACEASTA ETAPA" es el comodín de fecha libre, no un equipo real
            if ($this->isBye($team)) {
                continue;
            }

            $standings[] = [
                'pos' => (int) $cells[0],
                'team' => $team,
                'pj' => (int) $cells[2],
                'dg' => (int) $cells[6] - (int) $cells[7],
                'pts' => (int) rtrim($cells[8], 'pP '),
                'us' => $this->normalize($team) === $this->normalize($clubName),
            ];
        }

        return $standings;
    }

    /** "A.S New Castle" y "AS NEW CASTLE" tienen que matchear. */
    protected function normalize(string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($name));
    }

    /** "STA ACEASTA ETAPA" = descansa esta fecha (comodín), no un equipo real. */
    protected function isBye(string $team): bool
    {
        return str_contains($this->normalize($team), 'ACEASTAETAPA');
    }
}
