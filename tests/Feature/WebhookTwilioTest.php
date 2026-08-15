<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Services\WhatsApp\WhatsAppChannel;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    config(['services.twilio.token' => 'test-token-secreto']);
    $this->whatsapp = new FakeWhatsAppChannel();
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

function twilioPost($test, array $params, ?string $signature = null)
{
    $url = 'http://localhost/webhooks/twilio';

    if ($signature === null) {
        ksort($params);
        $payload = $url;
        foreach ($params as $k => $v) {
            $payload .= $k.$v;
        }
        $signature = base64_encode(hash_hmac('sha1', $payload, 'test-token-secreto', true));
    }

    return $test->withHeaders(['X-Twilio-Signature' => $signature])->post('/webhooks/twilio', $params);
}

it('rechaza un webhook con firma inválida', function () {
    twilioPost($this, ['MessageSid' => 'SM1', 'ButtonPayload' => 'att:1:in', 'From' => 'whatsapp:+40711111111'], 'firma-trucha')
        ->assertForbidden();
});

it('rechaza el webhook si Twilio no está configurado', function () {
    config(['services.twilio.token' => null]);

    $this->post('/webhooks/twilio', [])->assertForbidden();
});

it('el botón Voy del WhatsApp actualiza la asistencia con source whatsapp', function () {
    $member = Member::factory()->create();
    $event = Event::factory()->by($member)->create();

    twilioPost($this, [
        'MessageSid' => 'SM100',
        'From' => 'whatsapp:'.$member->user->phone,
        'ButtonPayload' => "att:{$event->id}:in",
    ])->assertOk();

    $att = Attendance::where('event_id', $event->id)->where('member_id', $member->id)->first();
    expect($att)->not->toBeNull()
        ->and($att->status)->toBe('in')
        ->and($att->source)->toBe('whatsapp');

    // Respuesta de cortesía con el conteo
    expect($this->whatsapp->texts)->toHaveCount(1)
        ->and($this->whatsapp->texts[0]['to'])->toBe($member->user->phone);
});

it('es idempotente: el mismo MessageSid no se procesa dos veces', function () {
    $member = Member::factory()->create();
    $event = Event::factory()->by($member)->create();

    twilioPost($this, [
        'MessageSid' => 'SM200',
        'From' => 'whatsapp:'.$member->user->phone,
        'ButtonPayload' => "att:{$event->id}:in",
    ])->assertOk();

    // Reintento de Twilio con el mismo sid pero otro payload: se ignora
    twilioPost($this, [
        'MessageSid' => 'SM200',
        'From' => 'whatsapp:'.$member->user->phone,
        'ButtonPayload' => "att:{$event->id}:out",
    ])->assertOk();

    expect(Attendance::where('event_id', $event->id)->where('member_id', $member->id)->value('status'))
        ->toBe('in');
});

it('ignora respuestas de números que no son del club del evento', function () {
    $event = Event::factory()->create();
    $ajeno = Member::factory()->create(); // member de otro club

    twilioPost($this, [
        'MessageSid' => 'SM300',
        'From' => 'whatsapp:'.$ajeno->user->phone,
        'ButtonPayload' => "att:{$event->id}:in",
    ])->assertOk();

    expect(Attendance::where('event_id', $event->id)->count())->toBe(0);
});

it('ignora payloads que no son de asistencia', function () {
    twilioPost($this, [
        'MessageSid' => 'SM400',
        'From' => 'whatsapp:+40711111111',
        'Body' => 'hola, ¿a qué hora era?',
    ])->assertOk();

    expect(Attendance::count())->toBe(0);
});
