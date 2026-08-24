<?php

use App\Models\Member;
use App\Models\Registration;
use App\Rules\ValidCnp;

/** Un CNP sintáctica y aritméticamente válido, para los tests. */
function cnpValido(string $base = '198071612345'): string
{
    $weights = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];
    $sum = 0;
    foreach (str_split($base) as $i => $d) {
        $sum += (int) $d * $weights[$i];
    }
    $control = $sum % 11 === 10 ? 1 : $sum % 11;

    return $base.$control;
}

// ── Modelo ────────────────────────────────────────────────────────────

it('marca completo y fija purge_after cuando no falta nada', function () {
    $reg = Registration::factory()->complete()->create([
        'status' => 'pendiente', 'submitted_at' => null, 'purge_after' => null,
    ]);

    $reg->refreshStatus();

    expect($reg->status)->toBe('completo')
        ->and($reg->submitted_at)->not->toBeNull()
        ->and($reg->purge_after->isAfter(now()->addDays(89)))->toBeTrue();
});

it('vuelve a pendiente si deja de estar completo, salvo enviado_federacion', function () {
    $reg = Registration::factory()->complete()->create();
    $reg->photo_path = null;
    $reg->refreshStatus();
    expect($reg->status)->toBe('pendiente')->and($reg->purge_after)->toBeNull();

    $enviado = Registration::factory()->complete()->create(['status' => 'enviado_federacion']);
    $enviado->photo_path = null;
    $enviado->refreshStatus();
    expect($enviado->fresh()->status)->toBe('enviado_federacion');
});

it('un rumano necesita CNP y un extranjero pasaporte', function () {
    $ro = Registration::factory()->complete()->make(['nationality' => 'RO', 'cnp' => null]);
    expect($ro->missingFields())->toContain('cnp')
        ->and($ro->missingFields())->not->toContain('passport_number');

    $ar = Registration::factory()->complete()->make(['passport_number' => null]);
    expect($ar->missingFields())->toContain('passport_number');
});

it('el detalle de federado solo es obligatorio si contestó que sí', function () {
    $si = Registration::factory()->complete()->make(['played_federated' => true, 'federated_details' => null]);
    expect($si->missingFields())->toContain('federated_details');

    $no = Registration::factory()->complete()->make(['played_federated' => false, 'federated_details' => null]);
    expect($no->missingFields())->toBe([]);
});

it('no permite dos fichas del mismo jugador en la misma temporada', function () {
    $reg = Registration::factory()->create();

    Registration::factory()->create([
        'member_id' => $reg->member_id, 'club_id' => $reg->club_id,
    ]);
})->throws(Illuminate\Database\QueryException::class);

// ── CNP ───────────────────────────────────────────────────────────────

it('rechaza CNP con formato o checksum inválido', function (string $cnp) {
    $v = validator(['cnp' => $cnp], ['cnp' => [new ValidCnp]]);
    expect($v->passes())->toBeFalse();
})->with([
    '1800101221145',   // dígito de control incorrecto (el correcto es 4)
    '123',
    'abcdefghijklm',
    '19807161234',     // 11 dígitos
]);

it('acepta un CNP con checksum correcto', function () {
    $v = validator(['cnp' => cnpValido()], ['cnp' => [new ValidCnp]]);
    expect($v->passes())->toBeTrue();
});

// ── Formulario ────────────────────────────────────────────────────────

it('guarda parcial: el jugador completa la mitad y ve qué le falta', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->post('/legitimacion', ['birth_date' => '1995-05-10', 'nationality' => 'AR'])
        ->assertRedirect();

    $reg = Registration::withoutGlobalScopes()->firstWhere('member_id', $member->id);
    expect($reg->birth_date->format('Y-m-d'))->toBe('1995-05-10')
        ->and($reg->status)->toBe('pendiente')
        ->and($reg->missingFields())->toContain('photo')
        ->and($reg->missingFields())->toContain('passport_number');
});

it('sube la foto al disco privado y la marca como entregada', function () {
    Storage::fake('local');
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->post('/legitimacion', ['photo' => Illuminate\Http\UploadedFile::fake()->image('carnet.jpg')])
        ->assertRedirect();

    $reg = Registration::withoutGlobalScopes()->firstWhere('member_id', $member->id);
    expect($reg->photo_path)->toStartWith("legitimacion/{$member->club_id}/{$member->id}/photo-");
    Storage::assertExists($reg->photo_path);
});

it('rechaza un CNP inválido en el formulario', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)
        ->post('/legitimacion', ['nationality' => 'RO', 'cnp' => '1800101221145'])
        ->assertSessionHasErrors('cnp');
});

it('la primera visita crea la ficha en pendiente, nunca "completa"', function () {
    $member = Member::factory()->create();

    $this->actingAs($member->user)->get('/legitimacion')
        ->assertInertia(fn ($page) => $page->where('registration.status', 'pendiente'));
});

it('el roster solo lo recibe el manager', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($player->user)->get('/legitimacion')
        ->assertInertia(fn ($page) => $page->component('Legitimacion')->where('roster', null));

    $this->actingAs($manager->user)->get('/legitimacion')
        ->assertInertia(fn ($page) => $page->component('Legitimacion')->has('roster', 2));
});

it('el roster del manager no mezcla clubes', function () {
    $managerA = Member::factory()->manager()->create();
    $memberB = Member::factory()->create(); // otro club
    Registration::factory()->complete()->create(['member_id' => $memberB->id, 'club_id' => $memberB->club_id]);

    $this->actingAs($managerA->user)->get('/legitimacion')
        ->assertInertia(fn ($page) => $page->has('roster', 1)
            ->where('roster.0.member_id', $managerA->id));
});

// ── Documentos: solo el manager, solo su club ─────────────────────────

it('un jugador no puede bajar la documentación de otro', function () {
    Storage::fake('local');
    Storage::put('legitimacion/doc.jpg', 'x');
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $otro = Member::factory()->for($manager->club)->create();
    $reg = Registration::factory()->create(['member_id' => $otro->id, 'club_id' => $otro->club_id, 'photo_path' => 'legitimacion/doc.jpg']);

    $this->actingAs($player->user)->get("/legitimacion/{$reg->id}/doc/photo")->assertForbidden();
    $this->actingAs($player->user)->get("/legitimacion/{$reg->id}/zip")->assertForbidden();
    $this->actingAs($manager->user)->get("/legitimacion/{$reg->id}/doc/photo")->assertOk();
});

it('un manager de otro club no ve nada', function () {
    $managerA = Member::factory()->manager()->create();
    $memberB = Member::factory()->create();
    $reg = Registration::factory()->complete()->create(['member_id' => $memberB->id, 'club_id' => $memberB->club_id]);

    $this->actingAs($managerA->user)->get("/legitimacion/{$reg->id}/doc/photo")->assertNotFound();
    $this->actingAs($managerA->user)->get("/legitimacion/{$reg->id}/zip")->assertNotFound();
});

it('el manager baja el ZIP con datos y archivos', function () {
    Storage::fake('local');
    Storage::put('legitimacion/photo.jpg', 'foto');
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();
    $reg = Registration::factory()->complete()->create([
        'member_id' => $player->id, 'club_id' => $player->club_id,
        'photo_path' => 'legitimacion/photo.jpg', 'passport_path' => null, 'id_doc_path' => null,
    ]);

    $response = $this->actingAs($manager->user)->get("/legitimacion/{$reg->id}/zip");
    $response->assertOk()->assertDownload();
});

// ── Recordatorio ──────────────────────────────────────────────────────

it('recuerda solo a los que no completaron', function () {
    $fake = new Tests\Support\FakeWhatsAppChannel();
    $this->app->instance(App\Services\WhatsApp\WhatsAppChannel::class, $fake);

    $manager = Member::factory()->manager()->create();
    $completo = Member::factory()->for($manager->club)->create();
    $pendiente = Member::factory()->for($manager->club)->create();
    Registration::factory()->complete()->create(['member_id' => $completo->id, 'club_id' => $completo->club_id]);

    $this->actingAs($manager->user)->post('/legitimacion/recordar')
        ->assertRedirect()->assertSessionHas('status', 2); // manager + pendiente

    $tos = array_column($fake->texts, 'to');
    expect($tos)->toContain($pendiente->user->phone)
        ->and($tos)->toContain($manager->user->phone)
        ->and($tos)->not->toContain($completo->user->phone);
});

it('un player no puede mandar el recordatorio', function () {
    $manager = Member::factory()->manager()->create();
    $player = Member::factory()->for($manager->club)->create();

    $this->actingAs($player->user)->post('/legitimacion/recordar')->assertForbidden();
});

// ── Purga ─────────────────────────────────────────────────────────────

it('purga archivos y datos sensibles a los 90 días, conservando el histórico', function () {
    Storage::fake('local');
    Storage::put('legitimacion/photo.jpg', 'x');
    Storage::put('legitimacion/id.jpg', 'x');

    $vencida = Registration::factory()->complete()->create([
        'photo_path' => 'legitimacion/photo.jpg', 'id_doc_path' => 'legitimacion/id.jpg',
        'passport_path' => null, 'purge_after' => now()->subDay(),
    ]);
    $reciente = Registration::factory()->complete()->create(['purge_after' => now()->addDays(30)]);

    $this->artisan('legitimacion:purgar')->assertSuccessful();

    $vencida->refresh();
    Storage::assertMissing('legitimacion/photo.jpg');
    expect($vencida->cnp)->toBeNull()
        ->and($vencida->passport_number)->toBeNull()
        ->and($vencida->photo_path)->toBeNull()
        ->and($vencida->full_name)->not->toBeNull()
        ->and($vencida->status)->toBe('completo')
        ->and($vencida->purged_at)->not->toBeNull()
        ->and($reciente->fresh()->passport_number)->toBe('AAB123456');

    // idempotente: correrla de nuevo no rompe
    $this->artisan('legitimacion:purgar')->assertSuccessful();
});
