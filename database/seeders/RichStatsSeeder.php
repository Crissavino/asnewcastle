<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Club;
use App\Models\Event;
use App\Models\MvpVote;
use App\Models\PlayerRating;
use Illuminate\Database\Seeder;

/**
 * Puebla un historial rico de partidos jugados (presentes confirmados,
 * votos de figura y calificaciones por partido) para lucir la pantalla
 * de estadísticas en el video demo. Idempotente.
 */
class RichStatsSeeder extends Seeder
{
    public function run(): void
    {
        $club = Club::where('slug', 'as-new-castle')->firstOrFail();
        $manager = $club->activeMembers()->where('role', 'manager')->firstOrFail();
        $members = $club->activeMembers()->get();
        $memberIds = $members->pluck('id')->all();

        // "Estrellas": el manager (que mostramos) + los 2 primeros del plantel.
        $stars = collect([$manager->id])
            ->merge(collect($memberIds)->reject(fn ($id) => $id === $manager->id)->take(2))
            ->unique()->values()->all();

        $opponents = ['FC Voluntari B', 'AS Pipera', 'Steaua Cartier', 'Dinamo Old Boys',
            'Real Otopeni', 'Juventus Ilfov', 'Lok București', 'Atlético Snagov',
            'Rapid Corbeanca', 'Sporting Băneasa', 'CS Afumați', 'Viitorul Domnești'];

        for ($i = 0; $i < 12; $i++) {
            $start = now()->subDays(6 + $i * 13)->setTime(11, 0);
            $event = Event::updateOrCreate(
                ['club_id' => $club->id, 'kind' => 'match', 'starts_at' => $start],
                [
                    'created_by_member_id' => $manager->id,
                    'opponent' => $opponents[$i % count($opponents)],
                    'is_home' => $i % 2 === 0,
                    'venue' => 'Teren Voluntari',
                    'kit' => $i % 2 === 0 ? 'home' : 'away',
                    'goals_for' => ($i * 3 + 1) % 6,
                    'goals_against' => ($i * 2) % 5,
                    'notified_at' => $start->copy()->subDays(3),
                    'attendance_confirmed_at' => $start->copy()->addHours(3),
                    'mvp_opened_at' => $start->copy()->addHours(2),
                    'mvp_closed_at' => $start->copy()->addHours(50),
                ]
            );
            $this->populate($event, $members, $memberIds, $stars, $i, true);
        }

        for ($i = 0; $i < 5; $i++) {
            $start = now()->subDays(9 + $i * 13)->setTime(19, 30);
            $event = Event::updateOrCreate(
                ['club_id' => $club->id, 'kind' => 'training', 'starts_at' => $start],
                [
                    'created_by_member_id' => $manager->id,
                    'is_home' => true,
                    'venue' => 'Teren Voluntari',
                    'kit' => 'home',
                    'attendance_confirmed_at' => $start->copy()->addHours(2),
                ]
            );
            $this->populate($event, $members, $memberIds, $stars, $i, false);
        }

        $this->command?->info('RichStats: 12 partidos + 5 entrenos con presentes, figura y calificaciones.');
    }

    protected function populate(Event $event, $members, array $memberIds, array $stars, int $seed, bool $withMvp): void
    {
        $present = [];
        foreach ($members->values() as $idx => $m) {
            $isStar = in_array($m->id, $stars, true);
            $roll = ($seed * 7 + $idx * 3) % 10;

            if ($isStar || $roll < 7) {
                $status = 'in';
                $attended = true;
            } elseif ($roll < 8) {
                $status = 'in';
                $attended = false; // faltazo: dijo "Voy" y no fue
            } else {
                $status = $roll < 9 ? 'out' : 'maybe';
                $attended = false;
            }

            Attendance::updateOrCreate(
                ['event_id' => $event->id, 'member_id' => $m->id],
                [
                    'status' => $status,
                    'attended' => $attended,
                    'responded_at' => $event->starts_at->copy()->subDay(),
                    'source' => $idx % 3 === 0 ? 'whatsapp' : 'app',
                ]
            );

            if ($attended) {
                $present[] = $m->id;
            }
        }

        if (! $withMvp || count($present) < 3) {
            return;
        }

        $starsPresent = array_values(array_intersect($stars, $present));

        foreach ($present as $vi => $voter) {
            $cands = array_values(array_diff($present, [$voter]));
            $pool = array_values(array_diff($starsPresent, [$voter])) ?: $cands;
            $voted = $pool[($seed + $vi) % count($pool)];
            MvpVote::updateOrCreate(
                ['event_id' => $event->id, 'voter_member_id' => $voter],
                ['voted_member_id' => $voted]
            );
        }

        foreach ($present as $rk => $rater) {
            $others = array_values(array_diff($present, [$rater]));
            for ($k = 0; $k < min(3, count($others)); $k++) {
                $rated = $others[($seed + $rk + $k) % count($others)];
                $isStar = in_array($rated, $stars, true);
                if ($isStar) {
                    $rating = ($seed + $k) % 4 === 0 ? 2 : 3;   // estrellas: cumplió/crack
                } else {
                    $rating = ($seed + $rk + $k) % 5 === 0 ? 1 : (($seed + $k) % 2 ? 2 : 3);
                }
                PlayerRating::updateOrCreate(
                    ['event_id' => $event->id, 'rater_member_id' => $rater, 'rated_member_id' => $rated],
                    ['rating' => $rating]
                );
            }
        }
    }
}
