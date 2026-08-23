<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerRatingController extends Controller
{
    /** Ternaria, anónima, una por compañero por partido; se puede cambiar. */
    public function store(Request $request, Event $event): RedirectResponse
    {
        $current = app(CurrentClub::class);
        $current->assertOwns($event);

        abort_unless($event->mvpPollOpen(), 400);

        $validated = $request->validate([
            'member_id' => ['required', 'integer'],
            'rating' => ['required', Rule::in([1, 2, 3])],
        ]);

        // Califica solo el que estuvo, y a uno mismo no: sería inflarse el promedio
        abort_unless($event->wasPresent($current->member()->id), 403);
        abort_if((int) $validated['member_id'] === $current->member()->id, 403);

        if (! $event->wasPresent((int) $validated['member_id'])) {
            throw ValidationException::withMessages([
                'member_id' => __('vestuario.mvp_not_candidate'),
            ]);
        }

        $event->playerRatings()->updateOrCreate(
            [
                'rater_member_id' => $current->member()->id,
                'rated_member_id' => $validated['member_id'],
            ],
            ['rating' => $validated['rating']],
        );

        return back();
    }
}
