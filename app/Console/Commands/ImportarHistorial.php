<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Services\FixtureScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Importa una temporada pasada al historial del club, desde el /program de esa
 * edición en frf-ajf. Se corre una vez por temporada ("una vez y listo"): los
 * resultados alimentan el historial contra cada rival del pronóstico.
 */
class ImportarHistorial extends Command
{
    protected $signature = 'tabla:historial
        {club : Slug del club}
        {url : URL del /program de la temporada a importar}';

    protected $description = 'Importa los resultados de una temporada pasada al historial (history_json)';

    public function handle(FixtureScraper $scraper): int
    {
        $club = Club::query()->where('slug', $this->argument('club'))->firstOrFail();

        $html = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NewCastleApp/1.0)'])
            ->timeout(30)
            ->get($this->argument('url'))
            ->throw()
            ->body();

        // Solo lo jugado: el historial son resultados, no partidos por venir
        $rows = collect($scraper->parse($html, $club->name))
            ->filter(fn ($row) => $row['played'])
            ->values();

        if ($rows->isEmpty()) {
            $this->error('No hay resultados del club en esa página (¿URL de otra temporada o cambió el formato?).');

            return self::FAILURE;
        }

        $merged = collect($club->history_json ?? [])
            ->concat($rows)
            // La etapa desambigua: en temporadas viejas hay filas sin fecha
            ->unique(fn ($row) => $row['etapa'].'|'.$row['date'].'|'.$row['opponent'])
            ->sortBy('date')
            ->values()
            ->all();

        $club->update(['history_json' => $merged]);

        $this->info("{$club->slug}: {$rows->count()} resultados importados, ".count($merged).' en el historial.');

        return self::SUCCESS;
    }
}
