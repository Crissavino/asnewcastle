<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Services\FixtureScraper;
use App\Services\StandingsScraper;
use Illuminate\Console\Command;
use Throwable;

class ImportStandings extends Command
{
    protected $signature = 'tabla:importar {--club= : Slug del club, si no se pasan todos}';

    protected $description = 'Scrapea el clasament y el fixture de la liga (standings_json + fixture_json)';

    public function handle(StandingsScraper $scraper, FixtureScraper $fixture): int
    {
        $clubs = Club::query()
            ->where(fn ($q) => $q->whereNotNull('standings_url')->orWhereNotNull('fixture_url'))
            ->when($this->option('club'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $failed = 0;

        foreach ($clubs as $club) {
            // El clasament y el fixture son independientes: si uno falla, el
            // otro igual se intenta, y un club no frena a los demás.
            if ($club->standings_url) {
                try {
                    $rows = $scraper->update($club);
                    $this->info("{$club->slug}: {$this->count($rows)} equipos en la tabla.");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("{$club->slug} (tabla): {$e->getMessage()}");
                    report($e);
                }
            }

            if ($club->fixture_url) {
                try {
                    $rows = $fixture->update($club);
                    $this->info("{$club->slug}: {$this->count($rows)} partidos en el fixture.");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("{$club->slug} (fixture): {$e->getMessage()}");
                    report($e);
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function count(array $rows): int
    {
        return \count($rows);
    }
}
