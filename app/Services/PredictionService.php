<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\PlayerRating;
use Illuminate\Support\Collection;

/**
 * Pronóstico del partido: chances de ganar/empatar/perder, calculadas al leer
 * (como StatsService, sin tablas nuevas). Mezcla la forma en el clasament, la
 * localía, el historial contra el rival, la racha, los confirmados y sus
 * entrenamientos recientes.
 *
 * Regla de producto: todo "Voy" SUMA. Las calificaciones del vestuario solo
 * modulan cuánto suma cada confirmado — nunca restan, así nadie puede ver que
 * su confirmación bajó el número.
 */
class PredictionService
{
    /** Plantel "ideal" de referencia para el factor de confirmados. */
    protected const IDEAL_SQUAD = 14;

    /** Peso del empate en el modelo (Davidson): ~28% con equipos parejos. */
    protected const DRAW_WEIGHT = 0.8;

    public function forEvent(Event $event, ?Member $viewer = null): ?array
    {
        if (! $event->isMatch() || $event->isCancelled() || $event->isFinished()) {
            return null;
        }

        $attendances = Attendance::query()
            ->where('status', 'in')
            ->where('event_id', $event->id)
            ->get();
        $confirmedIds = $attendances->pluck('member_id');

        $rivalRow = $this->rivalRow($event);

        $diff = $this->standingsDiff($event, $rivalRow)
            + ($event->is_home ? 0.15 : -0.15)
            + $this->headToHead($event)
            + $this->streak($event)
            + $this->confirmedBoost($event, $confirmedIds)
            + $this->trainingBoost($event, $confirmedIds);

        $prediction = [
            ...$this->probabilities($diff),
            'confirmed' => $confirmedIds->count(),
            'opponent_known' => $rivalRow !== null,
            'if_you_confirm' => null,
        ];

        // La zanahoria: al que no confirmó, cuánto subirían las chances con su Voy
        if ($viewer && ! $confirmedIds->contains($viewer->id)) {
            $boost = $this->confirmedBoost($event, $confirmedIds->concat([$viewer->id]))
                - $this->confirmedBoost($event, $confirmedIds);

            $prediction['if_you_confirm'] = $this->probabilities($diff + $boost)['win'];
        }

        return $prediction;
    }

    /** La fila del rival en el clasament, matcheando el nombre tipeado. */
    protected function rivalRow(Event $event): ?array
    {
        $opponent = $this->normalize($event->opponent ?? '');

        if ($opponent === '') {
            return null;
        }

        return collect($event->club->standings_json ?? [])
            ->reject(fn ($row) => $row['us'] ?? false)
            ->first(fn ($row) => $this->sameTeam($this->normalize($row['team']), $opponent));
    }

    /** Forma en el campeonato: puntos por partido y diferencia de gol, de ambos. */
    protected function standingsDiff(Event $event, ?array $rivalRow): float
    {
        $rows = collect($event->club->standings_json ?? []);
        $ours = $rows->first(fn ($row) => $row['us'] ?? false);

        $strength = function (?array $row) use ($rows): ?float {
            if (! $row || ($row['pj'] ?? 0) < 1) {
                return null;
            }

            return $row['pts'] / $row['pj'] + 0.15 * ($row['dg'] / $row['pj']);
        };

        $ourStrength = $strength($ours);

        // Rival desconocido (Copa, otra liga): se asume rival promedio de la tabla
        $rivalStrength = $strength($rivalRow) ?? $rows
            ->reject(fn ($row) => $row['us'] ?? false)
            ->map($strength)
            ->filter(fn ($s) => $s !== null)
            ->avg();

        if ($ourStrength === null || $rivalStrength === null) {
            return 0.0; // pretemporada: la tabla no dice nada todavía
        }

        return max(-1.3, min(1.3, 0.45 * ($ourStrength - $rivalStrength)));
    }

    /**
     * Historial contra este rival: primero los cruces con resultado cargado en
     * la app (incluye Copa); si no hay, los del fixture scrapeado. Los más
     * recientes pesan más.
     */
    protected function headToHead(Event $event): float
    {
        $opponent = $this->normalize($event->opponent ?? '');

        $results = Event::withoutGlobalScopes()
            ->where('club_id', $event->club_id)
            ->where('kind', 'match')
            ->whereNull('cancelled_at')
            ->whereKeyNot($event->id)
            ->whereNotNull('goals_for')
            ->whereNotNull('goals_against')
            ->orderByDesc('starts_at')
            ->get()
            ->filter(fn (Event $e) => $this->sameTeam($this->normalize($e->opponent ?? ''), $opponent))
            ->map(fn (Event $e) => $e->goals_for <=> $e->goals_against);

        if ($results->isEmpty()) {
            $results = collect($event->club->fixture_json ?? [])
                ->filter(fn ($row) => ($row['played'] ?? false)
                    && $this->sameTeam($this->normalize($row['opponent']), $opponent))
                ->reverse()
                ->map(fn ($row) => $row['is_home']
                    ? $row['home_score'] <=> $row['away_score']
                    : $row['away_score'] <=> $row['home_score']);
        }

        return 0.4 * $this->weightedAverage($results->take(3)->values());
    }

    /** Racha: los últimos 5 resultados del fixture scrapeado. */
    protected function streak(Event $event): float
    {
        $results = collect($event->club->fixture_json ?? [])
            ->filter(fn ($row) => $row['played'] ?? false)
            ->reverse()
            ->take(5)
            ->values()
            ->map(fn ($row) => $row['is_home']
                ? $row['home_score'] <=> $row['away_score']
                : $row['away_score'] <=> $row['home_score']);

        return 0.25 * $this->weightedAverage($results);
    }

    /**
     * Confirmados: cada Voy suma sobre una base pesimista. La calificación
     * acumulada del jugador (le costó/cumplió/crack) modula cuánto suma:
     * de 0.7 a 1.3, nunca negativo.
     */
    protected function confirmedBoost(Event $event, Collection $confirmedIds): float
    {
        if ($confirmedIds->isEmpty()) {
            return -0.72;
        }

        $ratingAvgs = PlayerRating::query()
            ->whereIn('rated_member_id', $confirmedIds)
            ->selectRaw('rated_member_id, avg(rating) as avg_rating')
            ->groupBy('rated_member_id')
            ->pluck('avg_rating', 'rated_member_id');

        $weighted = $confirmedIds->sum(function ($memberId) use ($ratingAvgs) {
            $avg = (float) ($ratingAvgs[$memberId] ?? PlayerRating::SOLID);

            return 1 + 0.3 * ($avg - PlayerRating::SOLID);
        });

        return 1.2 * ($weighted / self::IDEAL_SQUAD - 0.6);
    }

    /**
     * Entrenamientos de las últimas 4 semanas: qué tanto entrenó el plantel
     * que confirmó. Sin entrenamientos ni confirmados, no opina.
     */
    protected function trainingBoost(Event $event, Collection $confirmedIds): float
    {
        if ($confirmedIds->isEmpty()) {
            return 0.0;
        }

        $trainings = Event::withoutGlobalScopes()
            ->where('club_id', $event->club_id)
            ->where('kind', 'training')
            ->whereNull('cancelled_at')
            ->whereBetween('starts_at', [now()->subDays(28), now()])
            ->with('attendances')
            ->get();

        if ($trainings->isEmpty()) {
            return 0.0;
        }

        $possible = $trainings->count() * $confirmedIds->count();
        $attended = $trainings->sum(fn (Event $t) => $t->presentMemberIds()->intersect($confirmedIds)->count());

        return 0.5 * ($attended / $possible - 0.5);
    }

    /**
     * Modelo de Davidson: pesos exp(d), c, exp(-d) normalizados. Ganar es
     * estrictamente creciente en d, así "todo Voy suma" vale siempre.
     */
    protected function probabilities(float $diff): array
    {
        $win = exp($diff);
        $lose = exp(-$diff);
        $total = $win + self::DRAW_WEIGHT + $lose;

        return $this->roundToHundred([
            'win' => $win / $total,
            'draw' => self::DRAW_WEIGHT / $total,
            'lose' => $lose / $total,
        ]);
    }

    /** Enteros que suman 100 exacto (método del resto mayor). */
    protected function roundToHundred(array $shares): array
    {
        $floors = array_map(fn ($p) => (int) floor($p * 100), $shares);
        $left = 100 - array_sum($floors);

        $remainders = collect($shares)
            ->map(fn ($p, $key) => $p * 100 - $floors[$key])
            ->sortDesc()
            ->keys();

        foreach ($remainders->take($left) as $key) {
            $floors[$key]++;
        }

        return $floors;
    }

    /** Cruces recientes pesan más: 1, 0.6, 0.36... normalizado a [-1, 1]. */
    protected function weightedAverage(Collection $results): float
    {
        if ($results->isEmpty()) {
            return 0.0;
        }

        $sum = 0.0;
        $weights = 0.0;

        foreach ($results as $i => $result) {
            $weight = 0.6 ** $i;
            $sum += $weight * $result;
            $weights += $weight;
        }

        return $sum / $weights;
    }

    /**
     * "AS Moara Vlăsiei" y "Moara Vlasiei" tienen que matchear: se translitera
     * el rumano, se saca el prefijo de tipo de club y todo lo no alfanumérico.
     */
    protected function normalize(string $name): string
    {
        $name = strtr($name, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ț' => 't', 'ş' => 's', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ț' => 'T', 'Ş' => 'S', 'Ţ' => 'T',
        ]);

        $name = preg_replace('/[^A-Z0-9 ]/', '', mb_strtoupper($name));
        $name = preg_replace('/^(AS|ACS|CS|CSO|AFC|FC|ASF)\s+/', '', trim($name));

        return str_replace(' ', '', $name);
    }

    /** Igualdad o contención (nombres largos): "MOARAVLASIEI" ⊂ "MOARAVLASIEI2020". */
    protected function sameTeam(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b
            || (mb_strlen($a) >= 5 && mb_strlen($b) >= 5 && (str_contains($a, $b) || str_contains($b, $a)));
    }
}
