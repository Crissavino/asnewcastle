<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Member;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PresenceController extends Controller
{
    /**
     * El manager confirma quiénes estuvieron de verdad. No pisa lo que cada
     * uno respondió (status): eso queda como registro del "dijo que iba".
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);
        Gate::authorize('create', Event::class);
        abort_unless($event->isFinished() && ! $event->isCancelled(), 400);

        $validated = $request->validate([
            'present_ids' => ['present', 'array'],
            'present_ids.*' => ['integer'],
        ]);

        // Solo members del club: cualquier id ajeno se ignora
        $memberIds = Member::query()->where('club_id', $event->club_id)->pluck('id');
        $present = collect($validated['present_ids'])->map(fn ($id) => (int) $id)->intersect($memberIds);

        DB::transaction(function () use ($event, $present) {
            foreach ($event->attendances as $attendance) {
                $attendance->update(['attended' => $present->contains($attendance->member_id)]);
            }

            // Los que vinieron sin avisar: fila nueva sin status ni responded_at
            $present->diff($event->attendances->pluck('member_id'))
                ->each(fn ($memberId) => $event->attendances()->create([
                    'member_id' => $memberId,
                    'attended' => true,
                ]));

            $event->forceFill(['attendance_confirmed_at' => now()])->save();
        });

        return back();
    }
}
