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
