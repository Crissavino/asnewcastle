<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\SystemMessages;
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

        // A un evento cancelado no se le responde
        abort_if($event->isCancelled(), 400);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['in', 'maybe', 'out'])],
        ]);

        $member = app(CurrentClub::class)->member();

        $attendance = $event->attendances()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'status' => $validated['status'],
                'responded_at' => now(),
                'source' => 'app',
            ],
        );

        if ($attendance->status === 'in' && ($attendance->wasRecentlyCreated || $attendance->wasChanged('status'))) {
            app(SystemMessages::class)->confirmed($member, $event);
        }

        return back();
    }
}
