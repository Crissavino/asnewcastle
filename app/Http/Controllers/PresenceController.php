<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Member;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PresenceController extends Controller
{
    /**
     * El manager confirma quiénes estuvieron de verdad. No pisa lo que cada
     * uno respondió (status): eso queda como registro del "dijo que iba".
     * Junto con los presentes puede cargar el detalle del partido: quién fue
     * titular, quién entró, quién quedó en el banco, y los goles/asistencias.
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);
        Gate::authorize('create', Event::class);
        abort_unless($event->isFinished() && ! $event->isCancelled(), 400);

        $validated = $request->validate([
            'present_ids' => ['present', 'array'],
            'present_ids.*' => ['integer'],
            'detail' => ['sometimes', 'array'],
            'detail.*.id' => ['required', 'integer'],
            'detail.*.participation' => ['nullable', Rule::in(['starter', 'sub', 'bench'])],
            'detail.*.goals' => ['nullable', 'integer', 'min:0', 'max:20'],
            'detail.*.assists' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        // Solo members del club: cualquier id ajeno se ignora
        $memberIds = Member::query()->where('club_id', $event->club_id)->pluck('id');
        $present = collect($validated['present_ids'])->map(fn ($id) => (int) $id)->intersect($memberIds);
        $detail = collect($validated['detail'] ?? [])
            ->keyBy(fn ($d) => (int) $d['id'])
            ->only($present->all());

        if ($detail->where('participation', 'starter')->count() > 11) {
            throw ValidationException::withMessages(['detail' => __('agenda.too_many_starters')]);
        }

        // Con el resultado cargado, el detalle no puede contar más que el marcador.
        // Menos sí: un gol en contra del rival no tiene autor nuestro.
        if ($event->goals_for !== null) {
            if ($detail->sum(fn ($d) => (int) ($d['goals'] ?? 0)) > $event->goals_for) {
                throw ValidationException::withMessages(['detail' => __('agenda.too_many_goals')]);
            }

            if ($detail->sum(fn ($d) => (int) ($d['assists'] ?? 0)) > $event->goals_for) {
                throw ValidationException::withMessages(['detail' => __('agenda.too_many_assists')]);
            }
        }

        DB::transaction(function () use ($event, $present, $detail) {
            $fields = function (int $memberId) use ($present, $detail) {
                if (! $present->contains($memberId)) {
                    return ['attended' => false, 'participation' => null, 'goals' => 0, 'assists' => 0];
                }

                $d = $detail->get($memberId, []);

                return [
                    'attended' => true,
                    'participation' => $d['participation'] ?? null,
                    'goals' => (int) ($d['goals'] ?? 0),
                    'assists' => (int) ($d['assists'] ?? 0),
                ];
            };

            foreach ($event->attendances as $attendance) {
                $attendance->update($fields($attendance->member_id));
            }

            // Los que vinieron sin avisar: fila nueva sin status ni responded_at
            $present->diff($event->attendances->pluck('member_id'))
                ->each(fn ($memberId) => $event->attendances()->create([
                    'member_id' => $memberId,
                    ...$fields($memberId),
                ]));

            $event->forceFill(['attendance_confirmed_at' => now()])->save();
        });

        return back();
    }
}
