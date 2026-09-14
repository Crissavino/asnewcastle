<?php

use App\Models\Due;
use App\Models\DuePromise;
use App\Models\Member;
use App\Models\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/** Un deudor con la cuota de agosto y la de septiembre sin pagar. */
function deudorConDosMeses(): Member
{
    $member = Member::factory()->create();
    Due::factory()->forMember($member)->create([
        'period' => now()->startOfMonth()->subMonth()->toDateString(),
    ]);
    Due::factory()->forMember($member)->create();

    return $member;
}

it('el deudor recibe el prop debt con el total acumulado y los meses', function () {
    $member = deudorConDosMeses();

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('debt.total_cents', 24000)
            ->where('debt.months', 2)
            ->where('debt.show_popup', true)
            ->where('debt.promise', null)
        );
});

it('el jugador al día no recibe el prop debt', function () {
    $member = Member::factory()->create();
    Due::factory()->forMember($member)->paid()->create();

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('debt', null));
});

it('el popup se muestra una vez por día', function () {
    $member = deudorConDosMeses();

    $this->actingAs($member->user)->post('/cuota/aviso-visto')->assertRedirect();

    expect($member->fresh()->due_popup_seen_count)->toBe(1);

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('debt.show_popup', false));

    $this->travel(1)->days();

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page->where('debt.show_popup', true));
});

it('comprometerse a una fecha silencia el popup hasta esa fecha', function () {
    $member = deudorConDosMeses();

    $this->actingAs($member->user)
        ->post('/cuota/compromiso', ['promised_for' => now()->addDays(5)->toDateString()])
        ->assertRedirect();

    $promise = DuePromise::withoutGlobalScopes()->where('member_id', $member->id)->first();
    expect($promise->kind)->toBe('promise')
        ->and($promise->debt_cents)->toBe(24000)
        ->and($promise->status)->toBe('active')
        ->and($promise->club_id)->toBe($member->club_id);

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('debt.show_popup', false)
            ->where('debt.promise.active', true)
            ->where('debt.promise.broken', false)
        );

    // Pasa la fecha y la deuda sigue: vuelve el popup, con la promesa rota
    $this->travel(6)->days();

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('debt.show_popup', true)
            ->where('debt.promise.active', false)
            ->where('debt.promise.broken', true)
        );

    expect($promise->fresh()->status)->toBe('broken');
});

it('rechaza fechas en el pasado o a más de 30 días', function () {
    $member = deudorConDosMeses();

    $this->actingAs($member->user)
        ->post('/cuota/compromiso', ['promised_for' => now()->subDay()->toDateString()])
        ->assertSessionHasErrors('promised_for');

    $this->actingAs($member->user)
        ->post('/cuota/compromiso', ['promised_for' => now()->addDays(45)->toDateString()])
        ->assertSessionHasErrors('promised_for');
});

it('sin deuda no se puede crear un compromiso', function () {
    $member = Member::factory()->create();
    Due::factory()->forMember($member)->paid()->create();

    $this->actingAs($member->user)
        ->post('/cuota/compromiso', ['promised_for' => now()->addDays(5)->toDateString()])
        ->assertStatus(400);
});

it('la promesa queda cumplida si pagó lo que cubría, aunque después llegue la cuota nueva', function () {
    $member = Member::factory()->create();
    $due = Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)
        ->post('/cuota/compromiso', ['promised_for' => now()->addDays(5)->toDateString()]);

    // Paga la cuota que la promesa cubría
    $due->update(['status' => 'paid']);

    // Un mes después existe la cuota nueva del período siguiente, impaga
    $this->travel(1)->months();
    Due::factory()->forMember($member)->create([
        'period' => now()->startOfMonth()->toDateString(),
    ]);

    $this->actingAs($member->user)
        ->get('/agenda')
        ->assertInertia(fn (Assert $page) => $page
            ->where('debt.months', 1)
            ->where('debt.promise', null) // la promesa cumplida no se muestra
            ->where('debt.show_popup', true)
        );

    $promise = DuePromise::withoutGlobalScopes()->where('member_id', $member->id)->first();
    expect($promise->status)->toBe('kept');
});

it('"no puedo pagar" guarda el motivo y notifica al manager', function () {
    $manager = Member::factory()->manager()->create();
    $member = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($member)->create();

    $this->actingAs($member->user)
        ->post('/cuota/no-puedo', ['reason' => 'Me quedé sin laburo este mes'])
        ->assertRedirect();

    $row = DuePromise::withoutGlobalScopes()->where('member_id', $member->id)->first();
    expect($row->kind)->toBe('cant_pay')
        ->and($row->reason)->toBe('Me quedé sin laburo este mes');

    expect(Notification::where('member_id', $manager->id)
        ->where('body_key', 'notifications.cant_pay')->exists())->toBeTrue();
});

it('el manager ve el estado de cobranza de cada deudor', function () {
    $manager = Member::factory()->manager()->create();
    $comprometido = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($comprometido)->create();
    $sinRespuesta = Member::factory()->for($manager->club)->create();
    Due::factory()->forMember($sinRespuesta)->create();

    $date = now()->addDays(5)->toDateString();
    $this->actingAs($comprometido->user)->post('/cuota/compromiso', ['promised_for' => $date]);
    $this->actingAs($sinRespuesta->user)->post('/cuota/aviso-visto');
    $this->actingAs($sinRespuesta->user)->post('/cuota/no-puedo', ['reason' => 'sin laburo']);

    $this->actingAs($manager->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->where("cobranza.{$comprometido->id}.promised_for", $date)
            ->where("cobranza.{$comprometido->id}.status", 'active')
            ->where("cobranza.{$comprometido->id}.broken_count", 0)
            ->where("cobranza.{$sinRespuesta->id}.cant_pay.reason", 'sin laburo')
            ->where("cobranza.{$sinRespuesta->id}.seen_count", 1)
        );
});

it('el jugador no ve el prop cobranza', function () {
    $member = deudorConDosMeses();

    $this->actingAs($member->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page->missing('cobranza'));
});

it('los compromisos quedan aislados por club', function () {
    $member = deudorConDosMeses();
    $this->actingAs($member->user)
        ->post('/cuota/compromiso', ['promised_for' => now()->addDays(5)->toDateString()]);

    $managerB = Member::factory()->manager()->create();
    $deudorB = Member::factory()->for($managerB->club)->create();
    Due::factory()->forMember($deudorB)->create();

    $this->actingAs($managerB->user)
        ->get('/cuota')
        ->assertInertia(fn (Assert $page) => $page
            ->missing("cobranza.{$member->id}")
        );
});
