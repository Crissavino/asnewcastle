<?php

use App\Models\DeviceToken;
use App\Models\Member;
use App\Models\Message;
use App\Services\Push\PushSender;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePushSender;
use Tests\Support\FakeWhatsAppChannel;

beforeEach(function () {
    $this->push = new FakePushSender();
    $this->app->instance(PushSender::class, $this->push);
    $this->app->instance(WhatsAppChannel::class, new FakeWhatsAppChannel());
});

it('el vestuario pushea solo al que estaba al día (1 por primer sin leer)', function () {
    $author = Member::factory()->manager()->create();
    $club = $author->club;
    $alDia = Member::factory()->for($club)->create();
    $sinLeer = Member::factory()->for($club)->create();
    $mirando = Member::factory()->for($club)->create();

    foreach ([$author, $alDia, $sinLeer, $mirando] as $m) {
        DeviceToken::create(['user_id' => $m->user->id, 'token' => 'tok-'.$m->id, 'platform' => 'android']);
    }

    // Mensaje previo del autor, hace 5 minutos
    $m1 = Message::create(['club_id' => $club->id, 'member_id' => $author->id, 'body' => 'hola', 'is_system' => false]);
    DB::table('messages')->where('id', $m1->id)->update(['created_at' => now()->subMinutes(5)]);

    $alDia->update(['vestuario_read_at' => now()->subMinutes(2)]);    // leyó m1, no está mirando
    $sinLeer->update(['vestuario_read_at' => now()->subMinutes(10)]); // no leyó m1 → tiene sin leer
    $mirando->update(['vestuario_read_at' => now()]);                 // mirando ahora

    // El autor manda un mensaje nuevo → dispara el job (queue sync en tests)
    $this->actingAs($author->user)->post('/vestuario', ['body' => 'mensaje nuevo'])->assertRedirect();

    $tokens = $this->push->allTokens();
    expect($tokens)->toContain('tok-'.$alDia->id)      // estaba al día → push
        ->not->toContain('tok-'.$sinLeer->id)           // ya tenía sin leer → no
        ->not->toContain('tok-'.$mirando->id)           // lo está mirando → no
        ->not->toContain('tok-'.$author->id);           // es el autor → no
});

it('un segundo mensaje seguido no vuelve a pushear al que ya tenía sin leer', function () {
    $author = Member::factory()->manager()->create();
    $club = $author->club;
    $otro = Member::factory()->for($club)->create();
    DeviceToken::create(['user_id' => $otro->user->id, 'token' => 'tok-otro', 'platform' => 'android']);

    // 'otro' nunca abrió el vestuario (read_at null)
    // Primer mensaje: 'otro' estaba al día (no había nada) → push
    $this->actingAs($author->user)->post('/vestuario', ['body' => 'primero'])->assertRedirect();
    // Segundo mensaje seguido: 'otro' ya tiene sin leer → NO push
    $this->actingAs($author->user)->post('/vestuario', ['body' => 'segundo'])->assertRedirect();

    $veces = collect($this->push->sent)
        ->filter(fn ($s) => in_array('tok-otro', $s['tokens'], true))
        ->count();

    expect($veces)->toBe(1); // una sola push, por el primer mensaje sin leer
});

it('los mensajes del sistema no pushean', function () {
    $author = Member::factory()->manager()->create();
    $club = $author->club;
    $otro = Member::factory()->for($club)->create();
    DeviceToken::create(['user_id' => $otro->user->id, 'token' => 'tok-otro', 'platform' => 'android']);

    // Un mensaje de sistema no debería disparar push
    $sys = Message::create(['club_id' => $club->id, 'member_id' => null, 'body' => json_encode(['t' => 'x']), 'is_system' => true]);
    \App\Jobs\NotifyVestuarioMessage::dispatchSync($sys->id);

    expect($this->push->sent)->toBeEmpty();
});
