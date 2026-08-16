<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\SystemMessages;
use App\Services\WhatsApp\EventMessenger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Respuestas de los botones de la convocatoria de WhatsApp.
 * El payload del botón es "att:{event_id}:{in|maybe|out}".
 */
class TwilioWebhookController extends Controller
{
    public function __invoke(Request $request, EventMessenger $messenger): Response
    {
        // Idempotencia: Twilio puede reintentar el mismo mensaje
        $sid = $request->input('MessageSid', '');
        if ($sid !== '' && ! Cache::add('twilio-inbound:'.$sid, 1, now()->addDay())) {
            return $this->ok();
        }

        $payload = $request->input('ButtonPayload', '');
        if (! preg_match('/^att:(\d+):(in|maybe|out)$/', $payload, $m)) {
            return $this->ok();
        }

        [, $eventId, $status] = $m;

        $phone = str_replace('whatsapp:', '', $request->input('From', ''));
        $user = User::where('phone', $phone)->first();
        $event = Event::find($eventId);

        if (! $user || ! $event || $event->isCancelled()) {
            return $this->ok();
        }

        // El member correcto: el del club del evento. Si no es del club, se ignora.
        $member = $user->activeMembers()->where('club_id', $event->club_id)->first();

        if (! $member) {
            return $this->ok();
        }

        $attendance = $event->attendances()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'status' => $status,
                'responded_at' => now(),
                'source' => 'whatsapp',
            ],
        );

        if ($attendance->status === 'in' && ($attendance->wasRecentlyCreated || $attendance->wasChanged('status'))) {
            app(SystemMessages::class)->confirmed($member, $event);
        }

        $confirmed = $event->attendances()->where('status', 'in')->count();
        $messenger->sendReplyAck($member, $status, $confirmed);

        return $this->ok();
    }

    protected function ok(): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
            ->header('Content-Type', 'text/xml');
    }
}
