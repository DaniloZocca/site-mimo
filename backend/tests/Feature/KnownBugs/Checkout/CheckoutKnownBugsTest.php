<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\Checkout;

use App\Enums\MaterialType;
use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Events\OrderPaidEvent;
use App\Models\Color;
use App\Models\Order;
use App\Models\Size;
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
     * P1 — gêmeo de CheckoutValidationTest::test_cor_nao_vinculada_ao_produto_e_aceita
     */
    public function test_cor_nao_vinculada_ao_produto_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $foreign = Color::factory()->create();
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $foreign)]))->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    /**
     * P1 — gêmeo de CheckoutValidationTest::test_tamanho_nao_vinculado_ao_produto_e_aceito_com_modificador
     */
    public function test_tamanho_nao_vinculado_ao_produto_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $foreign = Size::factory()->modifier(-4000)->create();
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, $foreign)]))->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    /**
     * P2 — gêmeo de CheckoutValidationTest::test_cor_com_material_type_diferente_do_material_escolhido_e_aceita
     */
    public function test_cor_de_filamento_diferente_do_material_escolhido_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $color->update(['material_type' => MaterialType::PETG]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, null, 1, 'pla')]))->assertStatus(422);
    }

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
