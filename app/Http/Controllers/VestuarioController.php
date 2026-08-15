<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Support\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VestuarioController extends Controller
{
    public function show(): Response
    {
        $me = app(CurrentClub::class)->member();

        $messages = Message::query()
            ->with('member.user:id,name')
            ->latest('id')
            ->limit(80)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'system' => $m->is_system ? json_decode($m->body, true) : null,
                'body' => $m->is_system ? null : $m->body,
                'mine' => $m->member_id === $me->id,
                'author' => $m->member ? [
                    'name' => strtok($m->member->user->name ?? '', ' ') ?: $m->member->user->name,
                    'shirt_number' => $m->member->shirt_number,
                ] : null,
                'at' => $m->created_at->toIso8601String(),
            ]);

        return Inertia::render('Vestuario', [
            'messages' => $messages,
            'roster_count' => app(CurrentClub::class)->club()->activeMembers()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        Message::create([
            'member_id' => app(CurrentClub::class)->member()->id,
            'body' => $validated['body'],
            'is_system' => false,
        ]);

        return back();
    }
}
