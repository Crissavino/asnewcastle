<?php

use App\Models\Member;
use App\Services\Mollie\MollieGateway;
use App\Support\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Mollie\Api\Resources\Balance;
use Mollie\Api\Resources\Payout;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Fuera de una request nadie corre el middleware CurrentClub, así que los
 * tests que consultan el Ledger o Movement fijan el club activo a mano.
 */
function fijarClub(Member $member): void
{
    app(CurrentClub::class)->set($member->club, $member);
}

/**
 * Helpers de Mollie para la plata que entra: payouts y saldo. Mockean el
 * MollieGateway (no el SDK) y se acumulan sobre el mismo mock, así un test
 * puede fingir payout y saldo a la vez. Lo que devuelve cada método sale de un
 * holder guardado en el container (fresco en cada test), así que volver a
 * llamar al mismo helper REEMPLAZA la respuesta: es lo que necesita el payout
 * que primero llega `completed` y después `failed`.
 */
function mollieGatewayFake(): MockInterface
{
    $actual = app(MollieGateway::class);

    if ($actual instanceof MockInterface) {
        return $actual;
    }

    $mock = Mockery::mock(MollieGateway::class);
    $mock->shouldReceive('ready')->andReturn(true)->byDefault();
    app()->instance(MollieGateway::class, $mock);

    return $mock;
}

/** Lo que los helpers de Mollie van a devolver en este test. */
function mollieFakeHolder(): stdClass
{
    if (! app()->bound('mollie.fake')) {
        app()->instance('mollie.fake', new stdClass);
    }

    return app('mollie.fake');
}

/**
 * Un Payout de Mollie de mentira: extiende el recurso real (para cumplir el
 * return type de MollieGateway::getPayout) con constructor vacío. Solo trae lo
 * que lee el webhook. `deductionCents` es la comisión: por defecto NO viene,
 * que es lo que hace la API hoy (el recurso Payout del SDK no la declara).
 */
function molliePayoutResource(string $id, int $amountCents, string $status, string $currency = 'RON', ?int $deductionCents = null): Payout
{
    $payout = new class extends Payout
    {
        public function __construct() {}
    };

    $payout->id = $id;
    $payout->status = $status;
    $payout->amount = mollieFakeMoney($amountCents, $currency);
    $payout->createdAt = now()->toIso8601String();
    $payout->completedAt = $status === 'completed' ? now()->toIso8601String() : null;

    if ($deductionCents !== null) {
        $payout->deductions = mollieFakeMoney($deductionCents, $currency);
    }

    return $payout;
}

function mollieFakeMoney(int $cents, string $currency = 'RON'): stdClass
{
    return (object) ['value' => number_format($cents / 100, 2, '.', ''), 'currency' => $currency];
}

/** getPayout($id) devuelve este payout. */
function fakeMolliePayout(string $id, int $amountCents, string $status, string $currency = 'RON', ?int $deductionCents = null): Payout
{
    $payout = molliePayoutResource($id, $amountCents, $status, $currency, $deductionCents);

    // Acumulativo: dos llamadas con ids distintos conviven; dos con el mismo id
    // reemplazan (el payout que primero llega completed y después failed).
    $holder = mollieFakeHolder();
    $holder->payouts ??= [];
    $holder->payouts[$id] = $payout;
    $holder->payoutError = null;
    mollieFakeStubPayouts();

    return $payout;
}

/**
 * getPayout() falla. El código es el status HTTP: 404 (id desconocido, no hay
 * nada que reintentar) o cualquier otro (error transitorio). NO afecta a
 * listPayouts(): para eso está fakeMolliePayoutListFailure().
 */
function fakeMolliePayoutFailure(string $message = 'payout no encontrado', int $code = 404): void
{
    $holder = mollieFakeHolder();
    $holder->payoutError = new RuntimeException($message, $code);
    mollieFakeStubPayouts();
}

/** listPayouts() falla (sin permiso payouts.read, o Mollie caída). */
function fakeMolliePayoutListFailure(string $message = 'no tengo permiso payouts.read'): void
{
    $holder = mollieFakeHolder();
    $holder->listError = new RuntimeException($message);
    mollieFakeStubPayouts();
}

/**
 * La lista de payouts (para el chequeo diario que los vuelve a procesar).
 * Cada item: ['id' => ..., 'amountCents' => ..., 'status' => ...].
 *
 * @return array<int, Payout>
 */
function fakeMolliePayoutList(array $payouts): array
{
    $resources = [];

    foreach ($payouts as $p) {
        $resources[$p['id']] = molliePayoutResource(
            $p['id'],
            $p['amountCents'],
            $p['status'] ?? 'completed',
            $p['currency'] ?? 'RON',
            $p['deductionCents'] ?? null,
        );
    }

    $holder = mollieFakeHolder();
    $holder->payouts = $resources;
    $holder->payoutError = null;
    $holder->listError = null;
    mollieFakeStubPayouts();

    return array_values($resources);
}

/** Registra una sola vez los stubs de payouts; la respuesta sale del holder. */
function mollieFakeStubPayouts(): void
{
    $holder = mollieFakeHolder();

    if (isset($holder->payoutsStubbed)) {
        return;
    }

    $holder->payoutsStubbed = true;
    $mock = mollieGatewayFake();

    $mock->shouldReceive('getPayout')->andReturnUsing(function (string $id) use ($holder) {
        if (isset($holder->payoutError)) {
            throw $holder->payoutError;
        }

        return ($holder->payouts ?? [])[$id] ?? throw new RuntimeException('payout no encontrado: '.$id, 404);
    });

    $mock->shouldReceive('listPayouts')->andReturnUsing(function () use ($holder) {
        if (isset($holder->listError)) {
            throw $holder->listError;
        }

        return array_values($holder->payouts ?? []);
    });
}

/** El saldo que Mollie todavía no depositó: disponible + en camino. */
function fakeMollieBalance(int $availableCents, int $pendingCents, string $currency = 'RON'): Balance
{
    $balance = new class extends Balance
    {
        public function __construct() {}
    };

    $balance->id = 'bal_test';
    $balance->status = 'active';
    $balance->currency = $currency;
    $balance->availableAmount = mollieFakeMoney($availableCents, $currency);
    $balance->incomingAmount = mollieFakeMoney($pendingCents, $currency);
    $balance->outgoingAmount = mollieFakeMoney(0, $currency);

    $holder = mollieFakeHolder();
    $holder->balance = $balance;
    $holder->balanceError = null;
    mollieFakeStubBalance();

    return $balance;
}

/** Leer el saldo falla (sin permiso balances.read, o Mollie caída). */
function fakeMollieBalanceFailure(string $message = 'no tengo permiso balances.read'): void
{
    $holder = mollieFakeHolder();
    $holder->balance = null;
    $holder->balanceError = $message;
    mollieFakeStubBalance();
}

/** Registra una sola vez el stub del saldo; la respuesta sale del holder. */
function mollieFakeStubBalance(): void
{
    $holder = mollieFakeHolder();

    if (isset($holder->balanceStubbed)) {
        return;
    }

    $holder->balanceStubbed = true;

    mollieGatewayFake()->shouldReceive('getBalance')->andReturnUsing(function () use ($holder) {
        if ($holder->balanceError) {
            throw new RuntimeException($holder->balanceError);
        }

        return $holder->balance;
    });
}
