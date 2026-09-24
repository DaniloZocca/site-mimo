<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\DTOs\GatewayPayment;
use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ProductionLog;
use App\Services\PaymentApprovalValidator;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CapturesLogs;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * PaymentApprovalValidator no webhook: só libera a produção com o pagamento exatamente igual ao esperado.
 * Divergência: pedido segue pending_payment, log critical, ProductionLog interno e resposta 200.
 */
#[Group('characterization')]
class WebhookPaymentApprovalValidationTest extends CharacterizationTestCase
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
     * @param  array<string, mixed>  $overrides
     */
    private function apiReturns(array $overrides = []): void
    {
        $this->mpHttp->pushJson(array_merge(
            FakeMercadoPagoHttpClient::payment(123, 'approved', $this->order->order_number, 100.0),
            $overrides
        ), 200);
    }

    private function assertHeldForReview(string $reason): void
    {
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertNull($this->order->paid_at);
        Event::assertNotDispatched(OrderPaidEvent::class);

        $critical = $this->logsAtLevels(['critical'], $this->order->order_number);
        $this->assertCount(1, $critical);
        $this->assertContains($reason, $critical[0]->context['reasons']);
        $this->assertSame('123', $critical[0]->context['payment_id']);
        $this->assertSame($this->order->id, $critical[0]->context['order_id']);

        $log = $this->order->productionLogs()->sole();
        $this->assertTrue($log->is_internal);
        $this->assertSame(ProductionLog::CODE_PAYMENT_REVIEW, $log->code);
        $this->assertSame('123', $log->reference);
        $this->assertSame('pending_payment', $log->previous_status);
        $this->assertSame('pending_payment', $log->new_status);
        $this->assertStringContainsString($reason, $log->notes);
    }

    private function postAndExpectReview(): void
    {
        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson(['message' => 'Pagamento em revisão: divergência registrada.', 'order_status' => 'pending_payment']);
    }

    public function test_valor_exato_libera_producao_e_registra_codigo_de_aprovacao(): void
    {
        $this->apiReturns();

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'in_queue');

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $log = $this->order->productionLogs()->sole();
        $this->assertFalse($log->is_internal);
        $this->assertSame(ProductionLog::CODE_PAYMENT_APPROVED, $log->code);
        $this->assertSame('123', $log->reference);
        $this->assertSame('Pagamento aprovado via Webhook Mercado Pago (Payment ID: 123)', $log->notes);
        Event::assertDispatchedTimes(OrderPaidEvent::class, 1);
    }

    public function test_um_centavo_acima_nao_libera_e_gera_log_critical(): void
    {
        $this->apiReturns(['transaction_amount' => 100.01]);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('amount_mismatch');
    }

    public function test_um_centavo_abaixo_nao_libera(): void
    {
        $this->apiReturns(['transaction_amount' => 99.99]);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('amount_mismatch');
    }

    public function test_moeda_diferente_de_brl_nao_libera(): void
    {
        $this->apiReturns(['currency_id' => 'USD']);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('currency_mismatch');
    }

    public function test_moeda_ausente_nao_libera(): void
    {
        $payment = FakeMercadoPagoHttpClient::payment(123, 'approved', $this->order->order_number, 100.0);
        unset($payment['currency_id']);
        $this->mpHttp->pushJson($payment, 200);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('currency_mismatch');
    }

    public function test_external_reference_de_outro_pedido_nao_libera_nenhum_dos_dois(): void
    {
        // O pagamento aponta para outro pedido, que tem outro total: esse outro pedido é o localizado.
        $other = Order::factory()->create(['total_cents' => 5000]);
        $this->apiReturns(['external_reference' => $other->order_number]);
        $this->captureLogs();

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'pending_payment');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $other->refresh()->status);
        $this->assertSame(0, $this->order->productionLogs()->count());
        $this->assertStringContainsString('amount_mismatch', (string) $other->productionLogs()->where('is_internal', true)->value('notes'));
    }

    public function test_reference_mismatch_do_validador_com_referencia_diferente_do_pedido(): void
    {
        // Validador isolado: mesmo pedido, external_reference diferente do order_number.
        $validator = app(PaymentApprovalValidator::class);
        $payment = GatewayPayment::fromMercadoPago(
            ['external_reference' => 'MIMOO-OUTRO'] + FakeMercadoPagoHttpClient::payment(123, 'approved', amount: 100.0)
        );

        $this->assertSame(['reference_mismatch'], $validator->validate($this->order, $payment));
    }

    public function test_payment_id_diferente_do_gravado_no_checkout_nao_libera(): void
    {
        $this->recordCheckoutPayment('555');
        $this->apiReturns();
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('payment_id_mismatch');
    }

    public function test_payment_id_igual_ao_gravado_no_checkout_libera(): void
    {
        $this->recordCheckoutPayment('123');
        $this->apiReturns();

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'in_queue');

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $this->assertSame(1, OrderPayment::count(), 'O webhook só lê order_payments: não grava nada.');
    }

    public function test_sem_id_gravado_a_checagem_de_id_e_pulada_mas_o_valor_continua_obrigatorio(): void
    {
        $this->recordCheckoutPayment(null);
        $this->apiReturns(['transaction_amount' => 100.01]);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('amount_mismatch');
        $this->assertNotContains('payment_id_mismatch', $this->logsAtLevels(['critical'])[0]->context['reasons']);
    }

    public function test_live_mode_false_em_producao_nao_libera(): void
    {
        $this->app['env'] = 'production';
        $this->apiReturns(['live_mode' => false]);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('live_mode_mismatch');
    }

    public function test_live_mode_ausente_em_producao_nao_libera(): void
    {
        $this->app['env'] = 'production';
        $payment = FakeMercadoPagoHttpClient::payment(123, 'approved', $this->order->order_number, 100.0);
        unset($payment['live_mode']);
        $this->mpHttp->pushJson($payment, 200);
        $this->captureLogs();

        $this->postAndExpectReview();

        $this->assertHeldForReview('live_mode_mismatch');
    }

    public function test_live_mode_false_fora_de_producao_libera(): void
    {
        $this->apiReturns(['live_mode' => false]);

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'in_queue');

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_webhook_repetido_com_divergencia_nao_duplica_o_registro_interno(): void
    {
        $this->apiReturns(['transaction_amount' => 100.01]);
        $this->apiReturns(['transaction_amount' => 100.01]);

        $this->postSignedWebhook('123', requestId: 'req-1')->assertOk();
        $this->postSignedWebhook('123', requestId: 'req-2')->assertOk();

        $this->assertSame(1, $this->order->productionLogs()->where('code', ProductionLog::CODE_PAYMENT_REVIEW)->count());
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }

    public function test_aprovado_para_cancelado_gera_alerta_registro_interno_e_nao_reabre(): void
    {
        $this->order->forceFill(['status' => OrderStatus::CANCELLED])->save();
        $this->apiReturns();
        $this->apiReturns();
        $this->captureLogs();

        $this->postSignedWebhook('123', requestId: 'req-1')
            ->assertOk()
            ->assertExactJson(['message' => 'Evento idempotente já processado.', 'order_status' => 'cancelled']);
        $this->postSignedWebhook('123', requestId: 'req-2')->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->order->refresh()->status);
        $this->assertCount(2, $this->logsAtLevels(['critical'], $this->order->order_number));

        $log = $this->order->productionLogs()->sole();
        $this->assertTrue($log->is_internal);
        $this->assertSame(ProductionLog::CODE_PAYMENT_AFTER_CANCELLATION, $log->code);
        $this->assertSame('123', $log->reference);
        $this->assertSame('cancelled', $log->previous_status);
        $this->assertSame('cancelled', $log->new_status);
        $this->assertStringContainsString('verificar estorno', $log->notes);
        Event::assertNotDispatched(OrderPaidEvent::class);
    }

    /**
     * P1 — antes em KnownBugs (bug #10).
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
     * P2 — antes em KnownBugs (bug #16).
     */
    public function test_valor_pago_diferente_do_total_nao_libera_producao(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order, amountCents: 10001);

        $this->postSignedWebhook('123');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }

    private function recordCheckoutPayment(?string $externalId): void
    {
        OrderPayment::create([
            'order_id' => $this->order->id,
            'provider' => 'mercadopago',
            'method' => 'pix',
            'external_id' => $externalId,
            'status' => 'pending',
            'http_status' => 201,
            'order_status' => OrderStatus::PENDING_PAYMENT->value,
        ]);
    }
}
