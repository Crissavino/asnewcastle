<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;

class NotificationController extends Controller
{
    /** Al abrir la campanita se marcan como leídas las del jugador actual. */
    public function markRead(): RedirectResponse
    {
        $member = app(CurrentClub::class)->member();

        Notification::where('member_id', $member->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}
