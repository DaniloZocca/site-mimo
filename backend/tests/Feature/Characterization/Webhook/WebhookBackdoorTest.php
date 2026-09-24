<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * Atalho de simulação em código de produção: MercadoPagoService::getPayment() devolve
 * 'approved' para IDs iniciados por SIM_ ou TEST_ sem consultar a API.
 */
#[Group('characterization')]
class WebhookBackdoorTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaidEvent::class]);
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    public function test_get_payment_com_prefixo_sim_devolve_aprovado_sem_consultar_api(): void
    {
        // BUG CONHECIDO: backdoor SIM_/TEST_ em getPayment() — ver teste gêmeo em KnownBugs
        $result = app(MercadoPagoService::class)->getPayment("SIM_{$this->order->order_number}_1");

        $this->assertSame([
            'id' => "SIM_{$this->order->order_number}_1",
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $this->order->order_number,
            'transaction_amount' => 100.0,
        ], $result);
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_get_payment_com_prefixo_test_e_pedido_inexistente_devolve_aprovado_com_valor_100(): void
    {
        // BUG CONHECIDO: backdoor SIM_/TEST_ em getPayment() — ver teste gêmeo em KnownBugs
        $result = app(MercadoPagoService::class)->getPayment('TEST_QUALQUER-COISA');

        $this->assertSame('approved', $result['status']);
        $this->assertSame('QUALQUER-COISA', $result['external_reference']);
        $this->assertSame(100.0, $result['transaction_amount']);
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_com_segredo_e_assinatura_valida_id_sim_tambem_aprova_sem_api(): void
    {
        // BUG CONHECIDO: backdoor SIM_/TEST_ em getPayment() — ver teste gêmeo em KnownBugs
        $this->postSignedWebhook("SIM_{$this->order->order_number}_1")->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_webhook_forjado_com_id_sim_sem_segredo_nao_marca_pedido_como_pago(): void
    {
        // Corrigido no bug #3 de docs/known-bugs.md: o fail-closed barra o POST anônimo
        // antes de chegar ao atalho SIM_ (que continua existindo — bug #2).
        $this->setWebhookSecret('');

        $this->postWebhook("SIM_{$this->order->order_number}_1")->assertStatus(401);

        $this->order->refresh();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->status);
        $this->assertNull($this->order->paid_at);
        $this->assertCount(0, $this->mpHttp->requests);
        Event::assertNotDispatched(OrderPaidEvent::class);
    }
}
