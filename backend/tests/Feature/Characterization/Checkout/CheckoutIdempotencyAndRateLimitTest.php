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

    /**
     * Bug #7 corrigido (era gêmeo em KnownBugs): a mesma Idempotency-Key devolve o mesmo pedido.
     */
    public function test_duplo_envio_com_a_mesma_idempotency_key_devolve_o_mesmo_pedido(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(2, 'approved'));
        $headers = ['Idempotency-Key' => '3f1c1d52-7a8e-4a8f-9c8e-1b2f0d6a9e11']; // o header passou a exigir UUID

        $first = $this->postJson('/api/checkout', $payload, $headers);
        $second = $this->postJson('/api/checkout', $payload, $headers);

        $this->assertSame(1, Order::count());
        $this->assertSame($first->json('order_number'), $second->json('order_number'));
        $this->assertCount(1, $this->mpHttp->requests, 'Só uma cobrança deve ser criada.');
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
