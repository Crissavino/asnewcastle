<?php

use App\Models\Member;
use App\Models\Message;
use App\Services\Translation\LocaleGuesser;
use App\Services\Translation\NullTranslator;
use App\Services\Translation\Translator;

/** Traductor de prueba que cuenta cuántas veces se llamó (para verificar el caché). */
class CountingTranslator implements Translator
{
    public int $calls = 0;

    public function translate(string $text, string $to): array
    {
        $this->calls++;

        return ['text' => "[$to] $text", 'from' => 'es'];
    }
}

function chatMessage(Member $author, string $body, ?string $locale = 'es'): Message
{
    return Message::create([
        'club_id' => $author->club_id,
        'member_id' => $author->id,
        'body' => $body,
        'is_system' => false,
        'detected_locale' => $locale,
    ]);
}

it('el driver null devuelve el texto sin tocar', function () {
    expect((new NullTranslator())->translate('Hola equipo', 'ro')['text'])->toBe('Hola equipo');
});

it('detecta rumano y español localmente, sin API', function () {
    expect(LocaleGuesser::guess('Băieți, hai la meci'))->toBe('ro')
        ->and(LocaleGuesser::guess('Vamos que ganamos hoy'))->toBe('es');
});

it('un idioma ajeno al plantel queda como desconocido, no como español', function () {
    // Holandés (el caso de Joeri): sin señal ro/es/en → 'und'. Así el botón
    // de traducir aparece igual para un lector que ve la app en español.
    expect(LocaleGuesser::guess('Jongens, goede wedstrijd vandaag'))->toBe('und');
    // Árabe por rango unicode.
    expect(LocaleGuesser::guess('مرحبا شباب، مباراة اليوم'))->toBe('ar');
    // Inglés por palabras comunes.
    expect(LocaleGuesser::guess('see you at the match tomorrow'))->toBe('en');
});

it('guarda un mensaje de idioma desconocido sin romper (columna alcanza para und)', function () {
    $member = Member::factory()->create();

    // "Hola" no matchea ninguna lista -> 'und' (3 chars). La columna debe alcanzar.
    $this->actingAs($member->user)->post('/vestuario', ['body' => 'Hola'])->assertRedirect();

    expect(Message::withoutGlobalScopes()->latest('id')->first()->detected_locale)->toBe('und');
});

it('guarda el idioma detectado al enviar un mensaje', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)->post('/vestuario', ['body' => 'Hai la meci, băieți'])->assertRedirect();

    expect(Message::withoutGlobalScopes()->latest('id')->first()->detected_locale)->toBe('ro');
});

it('traduce al idioma del que lee (con el driver real, prefijado)', function () {
    $this->app->instance(Translator::class, new CountingTranslator());

    $reader = Member::factory()->manager()->create();
    $reader->user->update(['locale' => 'ro']);
    $author = Member::factory()->for($reader->club)->create();
    $msg = chatMessage($author, 'Hola equipo');

    $this->actingAs($reader->user)
        ->postJson("/vestuario/{$msg->id}/traducir")
        ->assertJson(['ok' => true, 'text' => '[ro] Hola equipo']);
});

it('cachea: el segundo que traduce el mismo mensaje no llama a la API otra vez', function () {
    $spy = new CountingTranslator();
    $this->app->instance(Translator::class, $spy);

    $reader = Member::factory()->manager()->create();
    $reader->user->update(['locale' => 'ro']);
    $reader2 = Member::factory()->for($reader->club)->create();
    $reader2->user->update(['locale' => 'ro']);
    $author = Member::factory()->for($reader->club)->create();
    $msg = chatMessage($author, 'Hola equipo');

    // 1er lector: llama a la API
    $this->actingAs($reader->user)->postJson("/vestuario/{$msg->id}/traducir")->assertJson(['ok' => true]);
    // 2do lector, mismo idioma: sale del caché, NO llama de nuevo
    $this->actingAs($reader2->user)->postJson("/vestuario/{$msg->id}/traducir")->assertJson(['ok' => true]);
    // el mismo lector otra vez: tampoco
    $this->actingAs($reader->user)->postJson("/vestuario/{$msg->id}/traducir")->assertJson(['ok' => true]);

    expect($spy->calls)->toBe(1);
});

it('corta a las 30 traducciones por usuario por hora', function () {
    $reader = Member::factory()->manager()->create();
    $reader->user->update(['locale' => 'ro']);
    $author = Member::factory()->for($reader->club)->create();

    foreach (range(1, 30) as $i) {
        $msg = chatMessage($author, "mensaje $i");
        $this->actingAs($reader->user)->postJson("/vestuario/{$msg->id}/traducir")->assertJson(['ok' => true]);
    }

    // La 31 (mensaje nuevo, no cacheado) ya no pasa
    $extra = chatMessage($author, 'uno mas');
    $this->actingAs($reader->user)->postJson("/vestuario/{$extra->id}/traducir")->assertJson(['ok' => false]);
});

it('al traducir, corrige el idioma detectado con la detección real (Azure)', function () {
    $this->app->instance(Translator::class, new CountingTranslator()); // from='es'

    $reader = Member::factory()->manager()->create();
    $reader->user->update(['locale' => 'ro']);
    $author = Member::factory()->for($reader->club)->create();
    $msg = chatMessage($author, 'Hola equipo', 'ro'); // mal detectado como rumano

    $this->actingAs($reader->user)->postJson("/vestuario/{$msg->id}/traducir")->assertJson(['ok' => true]);

    expect($msg->fresh()->detected_locale)->toBe('es'); // corregido
});

it('no rompe el chat si la API falla: devuelve ok=false', function () {
    $this->app->instance(Translator::class, new class implements Translator {
        public function translate(string $text, string $to): array
        {
            throw new RuntimeException('API caída');
        }
    });

    $reader = Member::factory()->manager()->create();
    $author = Member::factory()->for($reader->club)->create();
    $msg = chatMessage($author, 'Hola');

    $this->actingAs($reader->user)->postJson("/vestuario/{$msg->id}/traducir")->assertJson(['ok' => false]);
});

it('no se puede traducir un mensaje de otro club', function () {
    $reader = Member::factory()->manager()->create();
    $ajeno = Member::factory()->create(); // otro club
    $msg = chatMessage($ajeno, 'Hola');

    $this->actingAs($reader->user)->postJson("/vestuario/{$msg->id}/traducir")->assertNotFound();
});
