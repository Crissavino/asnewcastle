<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Services\StandingsScraper;
use Illuminate\Console\Command;
use Throwable;

class ImportStandings extends Command
{
    protected $signature = 'tabla:importar {--club= : Slug del club, si no se pasan todos}';

    protected $description = 'Scrapea el clasament de la liga y actualiza standings_json';

    public function handle(StandingsScraper $scraper): int
    {
        $clubs = Club::query()
            ->whereNotNull('standings_url')
            ->when($this->option('club'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $failed = 0;

        foreach ($clubs as $club) {
            try {
                $rows = $scraper->update($club);
                $this->info("{$club->slug}: {$this->countTeams($rows)} equipos importados.");
            } catch (Throwable $e) {
                // Un club que falla no frena a los demás
                $failed++;
                $this->error("{$club->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function countTeams(array $rows): int
    {
        return count($rows);
    }
}
