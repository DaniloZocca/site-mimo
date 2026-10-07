<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\Checkout;

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Comportamento CORRETO esperado do checkout. Estes testes FALHAM hoje.
 */
#[Group('known-bugs')]
class CheckoutKnownBugsTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    /**
     * P1 — gêmeo de CheckoutAtomicityAndGatewayTest::test_timeout_do_gateway_no_cartao_cancela_o_pedido_e_repete_a_mensagem
     */
    public function test_falha_do_gateway_mantem_pedido_pendente_e_responde_502_generico(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertStatus(502);
        $this->assertStringNotContainsString('cURL', (string) $response->json('message'));
        $this->assertStringNotContainsString('Falha na autorização', (string) $response->json('message'));
        $this->assertSame(OrderStatus::PENDING_PAYMENT, Order::sole()->status);
    }

    /**
     * P1 — gêmeo de CheckoutHappyPathTest::test_cartao_rejeitado_responde_201_e_pedido_fica_pendente
     * (Destino do pedido — cancelado ou "aguardando nova tentativa" — ainda a decidir; aqui só a resposta.)
     */
    public function test_cartao_rejeitado_responde_422_com_mensagem_amigavel(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(778, 'rejected', statusDetail: 'cc_rejected_insufficient_amount'));

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertStringNotContainsString('cc_rejected', (string) $response->json('message'));
    }

    /**
     * P2 — gêmeo de CheckoutHappyPathTest::test_cartao_aprovado_dispara_order_paid_sem_listener_e_nao_order_paid_event
     */
    public function test_cartao_aprovado_dispara_order_paid_event_com_listener_de_confirmacao(): void
    {
        Event::fake([OrderPaid::class, OrderPaidEvent::class]);
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        Event::assertDispatched(OrderPaidEvent::class);
    }

    /**
     * P2 — gêmeo de CheckoutIdempotencyAndRateLimitTest::test_contador_e_compartilhado_com_rastreio_e_frete_por_ip
     */
    public function test_rate_limit_do_checkout_e_independente_do_rastreio(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-XXXXXXXX', 'email' => 'a@b.com']);
        }

        $this->postJson('/api/checkout', [])->assertStatus(422);
    }
}
