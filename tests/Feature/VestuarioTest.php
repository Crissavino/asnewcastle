<?php

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppChannel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->whatsapp = new FakeWhatsAppChannel();
    $this->app->instance(WhatsAppChannel::class, $this->whatsapp);
});

it('manda un mensaje y aparece en el vestuario', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->post('/vestuario', ['body' => 'Llegaron las camisetas nuevas.'])
        ->assertRedirect();

    $this->actingAs($member->user)
        ->get('/vestuario')
        ->assertInertia(fn (Assert $page) => $page
            ->has('messages', 1)
            ->where('messages.0.body', 'Llegaron las camisetas nuevas.')
            ->where('messages.0.mine', true)
        );
});

it('el vestuario no pisa el diccionario de traducciones', function () {
    // Regresión: el prop "messages" del chat pisaba el prop compartido
    // de traducciones y la pestaña mostraba las keys crudas.
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->get('/vestuario')
        ->assertInertia(fn (Assert $page) => $page
            ->where('translations', fn ($t) => collect($t)->has('vestuario.placeholder'))
            ->has('messages')
        );
});

it('el vestuario está aislado por club', function () {
    $member = Member::factory()->create();
    $ajeno = Member::factory()->create(); // otro club

    $this->actingAs($ajeno->user)->post('/vestuario', ['body' => 'secreto del otro club']);

    $this->actingAs($member->user)
        ->get('/vestuario')
        ->assertInertia(fn (Assert $page) => $page->has('messages', 0));
});

it('crear un evento deja un mensaje del sistema', function () {
    $manager = Member::factory()->manager()->create();

    $this->actingAs($manager->user)->post('/eventos', [
        'kind' => 'match',
        'opponent' => 'AS Dascălu',
        'starts_at' => now()->addDays(2)->format('Y-m-d H:i'),
        'venue' => 'Teren Voluntari',
        'kit' => 'away',
    ]);

    $system = Message::withoutGlobalScopes()->where('is_system', true)->first();
    expect($system)->not->toBeNull();

    $body = json_decode($system->body, true);
    expect($body['key'])->toBe('system.event_created_match')
        ->and($body['params']['opponent'])->toBe('AS Dascălu');
});

it('confirmar asistencia deja un mensaje del sistema, pero solo al pasar a Voy', function () {
    $member = Member::factory()->create();
    $member->user->update(['name' => 'Diego Ferreyra']);
    $event = Event::factory()->by($member)->create();

    $this->actingAs($member->user)->post("/eventos/{$event->id}/asistencia", ['status' => 'in']);
    $this->actingAs($member->user)->post("/eventos/{$event->id}/asistencia", ['status' => 'in']); // repetido: no duplica

    $systems = Message::withoutGlobalScopes()->where('is_system', true)->get();
    expect($systems)->toHaveCount(1);

    $body = json_decode($systems->first()->body, true);
    expect($body['params']['name'])->toBe('Diego');
});

it('la confirmación por WhatsApp también deja mensaje del sistema', function () {
    config(['services.twilio.token' => 'test-token-secreto']);
    $member = Member::factory()->create();
    $event = Event::factory()->by($member)->create();

    $params = [
        'MessageSid' => 'SM900',
        'From' => 'whatsapp:'.$member->user->phone,
        'ButtonPayload' => "att:{$event->id}:in",
    ];
    ksort($params);
    $payload = 'http://localhost/webhooks/twilio';
    foreach ($params as $k => $v) {
        $payload .= $k.$v;
    }
    $sig = base64_encode(hash_hmac('sha1', $payload, 'test-token-secreto', true));

    $this->withHeaders(['X-Twilio-Signature' => $sig])->post('/webhooks/twilio', $params)->assertOk();

    expect(Message::withoutGlobalScopes()->where('is_system', true)->count())->toBe(1);
});

it('valida el mensaje: ni vacío ni de 500+ caracteres', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)->post('/vestuario', ['body' => ''])->assertSessionHasErrors('body');
    $this->actingAs($member->user)->post('/vestuario', ['body' => str_repeat('a', 501)])->assertSessionHasErrors('body');

    expect(Attendance::count())->toBe(0)
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});
