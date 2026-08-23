<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MvpVoteController extends Controller
{
    /** Un voto por member, se puede cambiar mientras la votación esté abierta. */
    public function store(Request $request, Event $event): RedirectResponse
    {
        $current = app(CurrentClub::class);
        $current->assertOwns($event);

        abort_unless($event->mvpPollOpen(), 400);

        $validated = $request->validate([
            'member_id' => ['required', 'integer'],
        ]);

        // Vota solo el que estuvo, y a sí mismo no
        abort_unless($event->wasPresent($current->member()->id), 403);
        abort_if((int) $validated['member_id'] === $current->member()->id, 403);

        if (! $event->wasPresent((int) $validated['member_id'])) {
            throw ValidationException::withMessages([
                'member_id' => __('vestuario.mvp_not_candidate'),
            ]);
        }

        $event->mvpVotes()->updateOrCreate(
            ['voter_member_id' => $current->member()->id],
            ['voted_member_id' => $validated['member_id']],
        );

        return back();
    }
}
