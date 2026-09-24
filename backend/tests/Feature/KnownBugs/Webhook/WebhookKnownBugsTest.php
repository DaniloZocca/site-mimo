<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CapturesLogs;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * Comportamento CORRETO esperado do webhook. Estes testes FALHAM hoje.
 * Quando o bug for corrigido: o gêmeo em Characterization quebra, este passa, e trocamos.
 */
#[Group('known-bugs')]
class WebhookKnownBugsTest extends CharacterizationTestCase
{
    use CapturesLogs;
    use SendsMercadoPagoWebhooks;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaidEvent::class]);
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    /**
     * P0 — gêmeo de WebhookSignatureTest::test_sem_segredo_configurado_aceita_requisicao_sem_nenhum_cabecalho
     */
    public function test_sem_segredo_configurado_webhook_e_rejeitado_fail_closed(): void
    {
        $this->setWebhookSecret('');
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postWebhook('123')->assertStatus(401);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }

    /**
     * P0 — gêmeo de WebhookBackdoorTest::test_get_payment_com_prefixo_sim_devolve_aprovado_sem_consultar_api
     */
    public function test_get_payment_nao_tem_atalho_sim_test_e_sempre_consulta_a_api(): void
    {
        $this->mpHttp->pushApiError(404, ['message' => 'Payment not found']);

        $result = app(MercadoPagoService::class)->getPayment("SIM_{$this->order->order_number}_1");

        $this->assertNull($result);
        $this->assertCount(1, $this->mpHttp->requests);
    }

    /**
     * P0 — gêmeo de WebhookBackdoorTest::test_sem_segredo_webhook_forjado_com_sim_marca_pedido_como_pago
     */
    public function test_webhook_forjado_com_id_sim_nao_marca_pedido_como_pago(): void
    {
        $this->setWebhookSecret('');
        $this->mpHttp->pushApiError(404);

        $this->postWebhook("SIM_{$this->order->order_number}_1");

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertNull($this->order->paid_at);
        Event::assertNotDispatched(OrderPaidEvent::class);
    }

    /**
     * P1 — gêmeo de WebhookIdempotencyAndEdgeCasesTest::test_aprovado_para_pedido_cancelado_e_ignorado_em_silencio
     */
    public function test_aprovado_para_pedido_cancelado_gera_alerta_e_registro_para_estorno(): void
    {
        $this->order->forceFill(['status' => OrderStatus::CANCELLED])->save();
        $this->apiReturnsPayment(123, 'approved', $this->order);
        $this->captureLogs();

        $this->postSignedWebhook('123')->assertOk();

        $this->assertNotEmpty(
            $this->logsAtLevels(['warning', 'critical', 'alert', 'emergency'], $this->order->order_number),
            'Esperado log warning/critical citando o pedido cancelado que recebeu pagamento aprovado.'
        );
        $this->assertTrue(
            $this->order->productionLogs()->where('notes', 'like', '%123%')->exists(),
            'Esperado registro do pagamento (payment id 123) no histórico do pedido para estorno.'
        );
    }

    /**
     * P2 — gêmeo de WebhookPaymentProcessingTest::test_valor_pago_maior_que_o_total_e_aceito
     */
    public function test_valor_pago_diferente_do_total_nao_libera_producao(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order, amountCents: 10001);

        $this->postSignedWebhook('123');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }
}
