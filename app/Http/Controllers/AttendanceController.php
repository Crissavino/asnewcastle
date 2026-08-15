<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    /**
     * Voy / Duda / No voy. Sin soft delete: si cambia de opinión,
     * se actualiza la fila (unique event_id + member_id).
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        app(CurrentClub::class)->assertOwns($event);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['in', 'maybe', 'out'])],
        ]);

        $event->attendances()->updateOrCreate(
            ['member_id' => app(CurrentClub::class)->member()->id],
            [
                'status' => $validated['status'],
                'responded_at' => now(),
                'source' => 'app',
            ],
        );

        return back();
    }
}
