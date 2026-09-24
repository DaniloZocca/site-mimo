<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Events\OrderPaidEvent;
use App\Jobs\ProcessOrderPaidNotification;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ProductionLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CapturesLogs;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * PaymentApprovalValidator no cartão síncrono: a mesma regra do webhook, aplicada à resposta
 * da criação do pagamento (POST /v1/payments), sem consulta adicional ao Mercado Pago.
 */
#[Group('characterization')]
class CheckoutCardApprovalValidationTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;
    use CapturesLogs;

    /** Total do simpleCheckout no cartão: 5000 + frete PAC do servidor 1490. */
    private const CARD_TOTAL_CENTS = 6490;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaid::class, OrderPaidEvent::class]);
    }

    public function test_cartao_aprovado_com_valor_exato_libera_e_grava_codigo_de_aprovacao(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('status', 'in_queue');

        $order = Order::sole();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);
        $approval = $order->productionLogs()->where('code', ProductionLog::CODE_PAYMENT_APPROVED)->sole();
        $this->assertSame('777', $approval->reference);
        $this->assertSame('Pagamento autorizado instantaneamente via cartão (ID: 777)', $approval->notes);
        $this->assertSame('777', OrderPayment::sole()->external_id);
    }

    public function test_cartao_aprovado_com_valor_divergente_fica_em_revisao_com_202(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved', amount: 64.91));
        $this->captureLogs();

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(202);

        $order = Order::sole();
        $response->assertExactJson([
            'code' => 'payment_under_review',
            'message' => 'Recebemos seu pagamento, mas ele precisa de uma conferência manual antes de irmos para a produção. Não pague de novo: entraremos em contato pelo e-mail do pedido.',
            'order_number' => $order->order_number,
            'tracking_token' => $order->tracking_token,
            'status' => 'pending_payment',
        ]);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertCount(1, $this->mpHttp->requests, 'Nenhuma nova cobrança nem consulta extra ao Mercado Pago.');
        Event::assertNotDispatched(OrderPaid::class);
        Queue::assertNotPushed(ProcessOrderPaidNotification::class);

        $critical = $this->logsAtLevels(['critical'], $order->order_number);
        $this->assertCount(1, $critical);
        $this->assertSame(['amount_mismatch'], $critical[0]->context['reasons']);
        $this->assertSame('card', $critical[0]->context['channel']);

        $review = $order->productionLogs()->where('code', ProductionLog::CODE_PAYMENT_REVIEW)->sole();
        $this->assertTrue($review->is_internal);
        $this->assertSame('777', $review->reference);

        $payment = OrderPayment::sole();
        $this->assertSame('777', $payment->external_id);
        $this->assertSame('approved', $payment->status);
        $this->assertSame(202, $payment->http_status);
        $this->assertTrue($order->isInPaymentReview());
    }

    public function test_cartao_aprovado_em_outra_moeda_fica_em_revisao(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved', currencyId: 'USD'));

        $this->postJson('/api/checkout', $payload)->assertStatus(202)->assertJsonPath('code', 'payment_under_review');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, Order::sole()->status);
    }

    public function test_cartao_aprovado_com_live_mode_false_em_producao_fica_em_revisao(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved', liveMode: false));
        $this->app['env'] = 'production';

        $this->postJson('/api/checkout', $payload)->assertStatus(202);

        $this->assertStringContainsString(
            'live_mode_mismatch',
            (string) Order::sole()->productionLogs()->where('code', ProductionLog::CODE_PAYMENT_REVIEW)->value('notes')
        );
    }

    public function test_replay_do_cartao_em_revisao_devolve_o_mesmo_202_sem_cobrar_de_novo(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved', amount: 64.91));
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key])->assertStatus(202);
        $second = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key])
            ->assertStatus(202)
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame(1, Order::count());
    }

    public function test_cartao_em_analise_nao_e_validado_nem_avanca_e_grava_o_id(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        // Valor divergente de propósito: in_process não passa pelo validador (o webhook decide depois).
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(779, 'in_process', amount: 1.0, statusDetail: 'pending_contingency'));
        $this->captureLogs();

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('status', 'pending_payment');

        $order = Order::sole();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame('779', OrderPayment::sole()->external_id);
        $this->assertSame(0, $order->productionLogs()->where('is_internal', true)->count());
        $this->assertSame([], $this->logsAtLevels(['critical']));
    }

    public function test_corrida_webhook_ja_aprovou_o_mesmo_pagamento_responde_aprovado_sem_duplicar(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));
        // Enquanto o POST do cartão está em andamento, o webhook do mesmo pagamento libera o pedido.
        $this->mpHttp->onSend(function (): void {
            $order = Order::sole();
            Order::whereKey($order->id)->update(['status' => OrderStatus::IN_QUEUE->value, 'paid_at' => now()]);
            $order->productionLogs()->create([
                'previous_status' => 'pending_payment',
                'new_status' => 'in_queue',
                'notes' => 'Pagamento aprovado via Webhook Mercado Pago (Payment ID: 777)',
                'created_at' => now(),
                'code' => ProductionLog::CODE_PAYMENT_APPROVED,
                'reference' => '777',
            ]);
        });
        $this->captureLogs();

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(201)
            ->assertJsonPath('status', 'in_queue')
            ->assertJsonPath('payment.status', 'approved');

        $order = Order::sole();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);
        $this->assertSame(1, $order->productionLogs()->where('new_status', 'in_queue')->count());
        $this->assertSame(0, $order->productionLogs()->where('is_internal', true)->count());
        $this->assertSame([], $this->logsAtLevels(['critical']));
        Event::assertNotDispatched(OrderPaid::class);
        Queue::assertNotPushed(ProcessOrderPaidNotification::class);
    }

    public function test_corrida_pedido_cancelado_durante_o_post_fica_em_revisao_e_nao_reabre(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));
        $this->mpHttp->onSend(function (): void {
            Order::query()->update(['status' => OrderStatus::CANCELLED->value]);
        });

        $this->postJson('/api/checkout', $payload)->assertStatus(202)->assertJsonPath('status', 'cancelled');

        $order = Order::sole();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertTrue(
            $order->productionLogs()->where('code', ProductionLog::CODE_PAYMENT_AFTER_CANCELLATION)->where('reference', '777')->exists()
        );
        Event::assertNotDispatched(OrderPaid::class);
    }
}
