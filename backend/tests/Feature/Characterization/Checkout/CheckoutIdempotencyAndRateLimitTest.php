<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Duplo envio e rate limit (throttle:10,1) do checkout.
 */
#[Group('characterization')]
class CheckoutIdempotencyAndRateLimitTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    public function test_duplo_envio_identico_cria_dois_pedidos_e_duas_cobrancas(): void
    {
        // BUG CONHECIDO: sem idempotência, o duplo clique cria dois pedidos — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(2, 'approved'));
        $headers = ['Idempotency-Key' => 'mesma-chave-do-front'];

        $first = $this->postJson('/api/checkout', $payload, $headers)->assertStatus(201);
        $second = $this->postJson('/api/checkout', $payload, $headers)->assertStatus(201);

        $this->assertSame(2, Order::count());
        $this->assertNotSame($first->json('order_number'), $second->json('order_number'));
        $this->assertNotSame($first->json('payment.idempotency_key'), $second->json('payment.idempotency_key'));
        $this->assertCount(2, $this->mpHttp->requests);
    }

    public function test_decima_primeira_requisicao_no_mesmo_minuto_retorna_429(): void
    {
        // Payload inválido (422) também consome o limite.
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/checkout', [])->assertStatus(422);
        }

        $this->postJson('/api/checkout', [])
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('message', 'Too Many Attempts.');
    }

    public function test_limite_libera_depois_de_um_minuto(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->postJson('/api/checkout', []);
        }

        $this->travel(61)->seconds();

        $this->postJson('/api/checkout', [])->assertStatus(422);
    }

    public function test_contador_e_compartilhado_com_rastreio_e_frete_por_ip(): void
    {
        // BUG CONHECIDO: checkout, rastreio e frete dividem o mesmo contador de rate limit por IP — ver teste gêmeo em KnownBugs
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-XXXXXXXX', 'email' => 'a@b.com'])->assertStatus(404);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/shipping/calculate', []);
        }

        $this->postJson('/api/checkout', [])->assertStatus(429);
    }
}
