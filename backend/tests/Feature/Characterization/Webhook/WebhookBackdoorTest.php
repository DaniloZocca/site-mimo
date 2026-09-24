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
 * IDs com cara de simulação (SIM_/TEST_) não têm tratamento especial: getPayment() sempre
 * consulta a API. O atalho de simulação foi removido (bug #2 de docs/known-bugs.md).
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

    public function test_get_payment_nao_tem_atalho_sim_test_e_sempre_consulta_a_api(): void
    {
        // Corrigido no bug #2 de docs/known-bugs.md.
        $this->mpHttp->pushApiError(404, ['message' => 'Payment not found']);
        $this->mpHttp->pushApiError(404, ['message' => 'Payment not found']);

        $service = app(MercadoPagoService::class);

        $this->assertNull($service->getPayment("SIM_{$this->order->order_number}_1"));
        $this->assertNull($service->getPayment('TEST_QUALQUER-COISA'));
        $this->assertCount(2, $this->mpHttp->requests);
    }

    public function test_webhook_assinado_com_id_sim_nao_aprova_o_pedido(): void
    {
        $this->mpHttp->pushApiError(404);

        $this->postSignedWebhook("SIM_{$this->order->order_number}_1")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Falha na verificação reversa do pagamento']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        Event::assertNotDispatched(OrderPaidEvent::class);
    }
}
