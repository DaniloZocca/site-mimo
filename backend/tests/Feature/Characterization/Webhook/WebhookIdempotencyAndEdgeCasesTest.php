<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * Duplicidade (sequencial), pedidos em estados finais/inexistentes e filtros de evento.
 * Corridas entre processos simultâneos NÃO são testadas aqui (lockForUpdate não trava no SQLite).
 */
#[Group('characterization')]
class WebhookIdempotencyAndEdgeCasesTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaidEvent::class]);
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    public function test_webhook_aprovado_duplicado_em_sequencia_processa_uma_vez_so(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'in_queue');

        $this->postSignedWebhook('123', requestId: 'req-characterization-2')
            ->assertOk()
            ->assertExactJson(['message' => 'Evento idempotente já processado.', 'order_status' => 'in_queue']);

        $this->assertSame(1, $this->order->productionLogs()->count());
        Event::assertDispatchedTimes(OrderPaidEvent::class, 1);
        // A segunda notificação ainda consulta a API antes de perceber a duplicidade.
        $this->assertCount(2, $this->mpHttp->requests);
    }

    public function test_recusa_para_pedido_ja_em_producao_nao_altera(): void
    {
        $this->order->forceFill(['status' => OrderStatus::PRINTING])->save();
        $this->apiReturnsPayment(123, 'charged_back', $this->order);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson(['message' => 'Evento idempotente já processado.', 'order_status' => 'printing']);

        $this->assertSame(OrderStatus::PRINTING, $this->order->refresh()->status);
    }

    public function test_pedido_inexistente_retorna_200(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(123, 'approved', 'MIMOO-NAOEXISTE', 100.0), 200);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson(['message' => 'Pedido não localizado']);
    }

    public function test_evento_que_nao_e_de_pagamento_e_ignorado_sem_consultar_api(): void
    {
        $this->postSignedWebhook('123', $this->webhookBody('123', type: 'merchant_order', action: 'merchant_order.updated'))
            ->assertOk()
            ->assertExactJson(['message' => 'Evento ignorado (não é pagamento direto)']);

        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_sem_type_usa_action_para_filtrar(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postSignedWebhook('123', ['action' => 'payment.created', 'data' => ['id' => '123']])
            ->assertOk()
            ->assertJsonPath('order_status', 'in_queue');
    }

    public function test_sem_data_id_no_corpo_e_ignorado(): void
    {
        // O MP também envia ?data.id= na query, mas o PHP converte "data.id" em "data_id";
        // o controller lê input('data.id') (corpo JSON aninhado) ou input('id').
        $this->postJson(self::WEBHOOK_URL.'?data.id=123&type=payment', ['type' => 'payment'], $this->signedHeaders(''))
            ->assertOk()
            ->assertExactJson(['message' => 'Evento ignorado (não é pagamento direto)']);

        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_id_na_raiz_do_corpo_e_aceito_como_data_id(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postWebhook('123', $this->signedHeaders('123'), ['type' => 'payment', 'id' => '123'])
            ->assertOk()
            ->assertJsonPath('order_status', 'in_queue');
    }
}
