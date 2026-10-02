<?php

use App\Models\Member;
use App\Services\Mollie\MollieGateway;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;

/**
 * El jugador al que Mollie le dio de baja el débito (se agotaron los 5
 * reintentos) tiene que poder volver a suscribirse desde la app. Antes no
 * podía: startSubscription cortaba apenas veía un mollie_subscription_id,
 * sin mirar si esa suscripción seguía viva.
 */

/**
 * Endpoint de suscripciones de mentira: cuenta las que se crean y devuelve el
 * estado pedido en getForId. Si $estado es null, getForId tira ApiException
 * (el 404 de una suscripción que ya no existe).
 */
function fakeSubsEndpoint(?string $estado): object
{
    return new class($estado)
    {
        /** @var array<int, array> */
        public array $creadas = [];

        public function __construct(private ?string $estado) {}

        public function getForId(string $customerId, string $subscriptionId, bool $testmode = false): object
        {
            if ($this->estado === 'red_caida') {
                throw new RuntimeException('connection timed out');
            }

            if ($this->estado === null) {
                throw (new ReflectionClass(ApiException::class))->newInstanceWithoutConstructor();
            }

            return (object) ['status' => $this->estado];
        }

        public function createForId(string $customerId, array $payload = [], bool $testmode = false): object
        {
            $this->creadas[] = $payload;

            return (object) ['id' => 'sub_nueva'];
        }
    };
}

/**
 * Gateway con un cliente Mollie de mentira. Igual que el Payment falso del
 * WebhookMollieTest: subclase con constructor vacío, solo con lo que se usa.
 * `subscriptions` se declara como propiedad real para que gane sobre el __get
 * del SDK.
 */
function gatewayCon(?string $estado): array
{
    $subs = fakeSubsEndpoint($estado);

    $client = new class($subs) extends MollieApiClient
    {
        public function __construct(public $subscriptions) {}
    };

    return [new MollieGateway($client), $subs];
}

it('si Mollie canceló la suscripción, crea una nueva y guarda el id', function () {
    $member = Member::factory()->create([
        'mollie_customer_id' => 'cst_1',
        'mollie_subscription_id' => 'sub_muerta',
        'subscription_status' => 'past_due',
        'custom_fee_cents' => 30000,
        'fee_type' => 'custom',
    ]);

    [$gateway, $subs] = gatewayCon('canceled');
    $gateway->startSubscription($member, 'https://app.test/webhooks/mollie');

    expect($subs->creadas)->toHaveCount(1)
        ->and($member->fresh()->mollie_subscription_id)->toBe('sub_nueva')
        ->and($member->fresh()->subscription_status)->toBe('active');
});

it('si la suscripción sigue activa, no duplica (el reintento del webhook es idempotente)', function () {
    $member = Member::factory()->create([
        'mollie_customer_id' => 'cst_2',
        'mollie_subscription_id' => 'sub_viva',
        'custom_fee_cents' => 30000,
        'fee_type' => 'custom',
    ]);

    [$gateway, $subs] = gatewayCon('active');
    $gateway->startSubscription($member, 'https://app.test/webhooks/mollie');

    expect($subs->creadas)->toBeEmpty()
        ->and($member->fresh()->mollie_subscription_id)->toBe('sub_viva');
});

it('una suscripción que ya no existe en Mollie se puede recrear', function () {
    $member = Member::factory()->create([
        'mollie_customer_id' => 'cst_3',
        'mollie_subscription_id' => 'sub_fantasma',
        'custom_fee_cents' => 30000,
        'fee_type' => 'custom',
    ]);

    [$gateway, $subs] = gatewayCon(null);
    $gateway->startSubscription($member, 'https://app.test/webhooks/mollie');

    expect($subs->creadas)->toHaveCount(1)
        ->and($member->fresh()->mollie_subscription_id)->toBe('sub_nueva');
});

it('ante una suspendida también recrea', function () {
    $member = Member::factory()->create([
        'mollie_customer_id' => 'cst_4',
        'mollie_subscription_id' => 'sub_susp',
        'custom_fee_cents' => 30000,
        'fee_type' => 'custom',
    ]);

    [$gateway, $subs] = gatewayCon('suspended');
    $gateway->startSubscription($member, 'https://app.test/webhooks/mollie');

    expect($subs->creadas)->toHaveCount(1);
});

it('ante una caída de red no recrea nada: cobrar dos veces es peor que esperar', function () {
    $member = Member::factory()->create([
        'mollie_customer_id' => 'cst_5',
        'mollie_subscription_id' => 'sub_dudosa',
    ]);

    [$gateway, $subs] = gatewayCon('red_caida');
    $gateway->startSubscription($member, 'https://app.test/webhooks/mollie');

    expect($subs->creadas)->toBeEmpty()
        ->and($member->fresh()->mollie_subscription_id)->toBe('sub_dudosa');
});
