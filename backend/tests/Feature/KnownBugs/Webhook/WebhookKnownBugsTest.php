<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
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
