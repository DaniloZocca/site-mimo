<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\Tracking;

use App\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Comportamento CORRETO esperado do rastreio. FALHAM hoje.
 */
#[Group('known-bugs')]
class TrackingKnownBugsTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    /**
     * P1 — gêmeo de TrackingLookupTest::test_prefixo_de_um_digito_e_aceito
     */
    public function test_prefixo_de_um_digito_nao_localiza_o_pedido(): void
    {
        $order = Order::factory()->create(['order_number' => 'MIMOO-ABC12345', 'customer_cpf' => '52998224725']);

        $response = $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '5']);

        $this->assertContains($response->getStatusCode(), [404, 422]);
        $this->assertStringNotContainsString($order->tracking_token, $response->getContent());
    }

    /**
     * P2 — gêmeo de TrackingLookupTest::test_get_com_cpf_na_query_string_funciona
     */
    public function test_rastreio_so_aceita_post(): void
    {
        Order::factory()->create(['order_number' => 'MIMOO-ABC12345', 'customer_cpf' => '52998224725']);

        $this->getJson('/api/orders/track?order_number=MIMOO-ABC12345&cpf_prefix=52998224725')->assertStatus(405);
    }

    /**
     * P1 — gêmeo de TrackingResponseTest::test_timeline_expoe_id_de_pagamento_do_gateway
     */
    public function test_timeline_publica_nao_expoe_id_de_pagamento(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(987654321, 'approved'));
        $token = $this->postJson('/api/checkout', $payload)->json('tracking_token');

        $body = $this->postJson('/api/orders/track', ['tracking_token' => $token])->getContent();

        $this->assertStringNotContainsString('987654321', $body);
    }

    /**
     * P1 — gêmeo de TrackingResponseTest::test_timeline_expoe_mensagem_de_erro_do_gateway
     */
    public function test_timeline_publica_nao_expoe_erro_do_gateway(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out'));
        $orderNumber = $this->postJson('/api/checkout', $payload)->json('order_number');
        $token = Order::where('order_number', $orderNumber)->value('tracking_token');

        $body = $this->postJson('/api/orders/track', ['tracking_token' => $token])->getContent();

        $this->assertStringNotContainsString('cURL', $body);
        $this->assertStringNotContainsString('gateway', $body);
    }
}
