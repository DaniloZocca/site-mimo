<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * Camadas D–E: consulta reversa na API e mutação do pedido.
 */
#[Group('characterization')]
class WebhookPaymentProcessingTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaidEvent::class]);
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    public function test_aprovado_move_para_in_queue_grava_log_e_dispara_evento(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Pagamento aprovado e pedido encaminhado para a fila de manufatura 3D.',
                'order_status' => 'in_queue',
            ]);

        $this->order->refresh();
        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->status);
        $this->assertSame(self::FROZEN_NOW, $this->order->paid_at?->format('Y-m-d H:i:s'));

        $logs = $this->order->productionLogs()->get();
        $this->assertCount(1, $logs);
        $this->assertSame('pending_payment', $logs[0]->previous_status);
        $this->assertSame('in_queue', $logs[0]->new_status);
        $this->assertSame('Pagamento aprovado via Webhook Mercado Pago (Payment ID: 123)', $logs[0]->notes);

        Event::assertDispatchedTimes(OrderPaidEvent::class, 1);
        Event::assertDispatched(OrderPaidEvent::class, fn (OrderPaidEvent $e): bool => $e->order->is($this->order) && $e->paymentId === '123');
    }

    public function test_status_vem_da_consulta_a_api_e_nao_do_corpo_do_webhook(): void
    {
        // O corpo "diz" aprovado; a API diz pending. Vale a API.
        $this->apiReturnsPayment(123, 'pending', $this->order);
        $body = $this->webhookBody('123') + ['status' => 'approved'];
        $body['data']['status'] = 'approved';

        $this->postSignedWebhook('123', $body)
            ->assertOk()
            ->assertExactJson(['message' => 'Notificação processada com sucesso.', 'payment_status' => 'pending']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame('GET', $this->mpHttp->requests[0]->getMethod());
        $this->assertSame('/v1/payments/123', $this->mpHttp->requests[0]->getUri());
        Event::assertNotDispatched(OrderPaidEvent::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cancellingStatuses(): array
    {
        return [
            'rejected' => ['rejected'],
            'cancelled' => ['cancelled'],
            'refunded' => ['refunded'],
            'charged_back' => ['charged_back'],
        ];
    }

    #[DataProvider('cancellingStatuses')]
    public function test_status_de_recusa_cancela_o_pedido(string $gatewayStatus): void
    {
        $this->apiReturnsPayment(123, $gatewayStatus, $this->order);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson(['message' => 'Notificação processada com sucesso.', 'payment_status' => $gatewayStatus]);

        $this->order->refresh();
        $this->assertSame(OrderStatus::CANCELLED, $this->order->status);
        $this->assertNull($this->order->paid_at);

        $log = $this->order->productionLogs()->sole();
        $this->assertSame('pending_payment', $log->previous_status);
        $this->assertSame('cancelled', $log->new_status);
        $this->assertSame("Status de gateway recebido: {$gatewayStatus}", $log->notes);
        Event::assertNotDispatched(OrderPaidEvent::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function neutralStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'in_process' => ['in_process'],
            'authorized' => ['authorized'],
            'in_mediation' => ['in_mediation'],
        ];
    }

    #[DataProvider('neutralStatuses')]
    public function test_status_intermediario_nao_altera_o_pedido(string $gatewayStatus): void
    {
        $this->apiReturnsPayment(123, $gatewayStatus, $this->order);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson(['message' => 'Notificação processada com sucesso.', 'payment_status' => $gatewayStatus]);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertSame(0, $this->order->productionLogs()->count());
    }

    public function test_valor_pago_menor_que_o_total_retorna_422_e_nao_altera(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order, amountCents: 9999);

        $this->postSignedWebhook('123')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Divergência de valores financeiros detectada.']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertSame(0, $this->order->productionLogs()->count());
    }

    public function test_valor_pago_igual_ao_total_e_aceito(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order, amountCents: 10000);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_valor_pago_maior_que_o_total_e_aceito(): void
    {
        // BUG CONHECIDO: valor pago acima do total é aceito (doc exige igual) — ver teste gêmeo em KnownBugs
        $this->apiReturnsPayment(123, 'approved', $this->order, amountCents: 10001);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_recusa_com_valor_menor_retorna_422_e_nao_cancela(): void
    {
        // PERGUNTA: a checagem de valor roda antes da checagem de status. Um pagamento
        // 'rejected' com valor menor devolve 422 (o MP reenvia) e o pedido NÃO é cancelado. Intencional?
        $this->apiReturnsPayment(123, 'rejected', $this->order, amountCents: 100);

        $this->postSignedWebhook('123')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Divergência de valores financeiros detectada.']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }

    public function test_valor_com_centavos_fracionados_e_arredondado(): void
    {
        // transaction_amount 99.995 → round(9999.5) = 10000 centavos → aceito para total 10000.
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(123, 'approved', $this->order->order_number, 99.995), 200);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_falha_na_consulta_da_api_retorna_422(): void
    {
        $this->mpHttp->pushApiError(404, ['message' => 'Payment not found']);

        $this->postSignedWebhook('123')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Falha na verificação reversa do pagamento']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }

    public function test_pagamento_sem_external_reference_retorna_200_sem_alterar(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(123, 'approved', '', 100.0), 200);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson(['message' => 'external_reference ausente']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }

    public function test_data_id_nao_numerico_consulta_o_pagamento_zero(): void
    {
        // getPayment faz (int) 'abc' → 0 e consulta /v1/payments/0.
        $this->mpHttp->pushApiError(404);

        $this->postSignedWebhook('abc')->assertStatus(422);

        $this->assertSame('/v1/payments/0', $this->mpHttp->requests[0]->getUri());
    }
}
