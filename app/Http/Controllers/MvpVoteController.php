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

        $isCandidate = $event->attendances()
            ->where('status', 'in')
            ->where('member_id', $validated['member_id'])
            ->exists();

        if (! $isCandidate) {
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
