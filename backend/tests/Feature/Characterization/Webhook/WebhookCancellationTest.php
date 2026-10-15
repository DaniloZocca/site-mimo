<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use App\Services\CheckoutIdempotencyService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-17: cancelamento pelo webhook só pelo pagamento do próprio pedido, com update condicional (bug #24),
 * e OrderPaidEvent disparado só depois do commit.
 */
#[Group('characterization')]
class WebhookCancellationTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    public function test_recusa_de_pagamento_que_nao_e_do_pedido_nao_cancela(): void
    {
        $this->recordCheckoutPayment('777', 'pending');
        $this->apiReturnsPayment(999, 'rejected', $this->order);

        $this->postSignedWebhook('999')
            ->assertOk()
            ->assertExactJson(['message' => 'Notificação ignorada: pagamento não pertence a este pedido.', 'payment_status' => 'rejected']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertSame(0, $this->order->productionLogs()->count());
    }

    public function test_recusa_do_pagamento_gravado_cancela_o_pedido(): void
    {
        $this->recordCheckoutPayment('777', 'rejected');
        $this->apiReturnsPayment(777, 'rejected', $this->order);

        $this->postSignedWebhook('777')
            ->assertOk()
            ->assertExactJson(['message' => 'Notificação processada com sucesso.', 'payment_status' => 'rejected']);

        $this->assertSame(OrderStatus::CANCELLED, $this->order->refresh()->status);
        $log = $this->order->productionLogs()->sole();
        $this->assertSame('pending_payment', $log->previous_status);
        $this->assertSame('cancelled', $log->new_status);
    }

    public function test_pedido_so_com_erro_do_gateway_sem_id_e_cancelado_pela_recusa(): void
    {
        // Timeout no checkout (M-08): a linha de pagamento não tem id; a cobrança criada apesar do timeout foi recusada.
        $this->order->payments()->create([
            'provider' => 'mercadopago',
            'method' => 'credit_card',
            'status' => CheckoutIdempotencyService::PAYMENT_STATUS_ERROR,
            'http_status' => 502,
            'order_status' => OrderStatus::PENDING_PAYMENT->value,
        ]);
        $this->apiReturnsPayment(555, 'rejected', $this->order);

        $this->postSignedWebhook('555')->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->order->refresh()->status);
    }

    public function test_corrida_pedido_cancelado_no_painel_depois_da_leitura_nao_duplica_o_log(): void
    {
        $this->recordCheckoutPayment('777', 'pending');
        $this->apiReturnsPayment(777, 'cancelled', $this->order);
        $this->changeStatusRightAfterNextRead(OrderStatus::CANCELLED);

        $this->postSignedWebhook('777')
            ->assertOk()
            ->assertExactJson(['message' => 'Evento idempotente já processado.', 'order_status' => 'cancelled']);

        $this->assertSame(OrderStatus::CANCELLED, $this->order->refresh()->status);
        $this->assertSame(0, $this->order->productionLogs()->count());
    }

    public function test_corrida_pedido_liberado_depois_da_leitura_nao_e_cancelado(): void
    {
        $this->recordCheckoutPayment('777', 'pending');
        $this->apiReturnsPayment(777, 'refunded', $this->order);
        $this->changeStatusRightAfterNextRead(OrderStatus::IN_QUEUE);

        $this->postSignedWebhook('777')
            ->assertOk()
            ->assertExactJson(['message' => 'Evento idempotente já processado.', 'order_status' => 'in_queue']);

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $this->assertSame(0, $this->order->productionLogs()->count());
    }

    public function test_order_paid_event_do_webhook_dispara_depois_do_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new OrderPaidEvent($this->order));

        $baseLevel = DB::transactionLevel();
        $seen = [];
        Event::listen(OrderPaidEvent::class, function (OrderPaidEvent $event) use (&$seen): void {
            $seen[] = [
                'level' => DB::transactionLevel(),
                'status' => DB::table('orders')->where('id', $event->order->id)->value('status'),
            ];
        });
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame([['level' => $baseLevel, 'status' => OrderStatus::IN_QUEUE->value]], $seen);
    }

    private function recordCheckoutPayment(string $externalId, string $status): void
    {
        $this->order->payments()->create([
            'provider' => 'mercadopago',
            'method' => 'credit_card',
            'external_id' => $externalId,
            'status' => $status,
            'http_status' => 201,
            'order_status' => OrderStatus::PENDING_PAYMENT->value,
        ]);
    }

    /**
     * Outro processo (painel ou outra notificação) muda o pedido logo depois que o webhook o lê.
     */
    private function changeStatusRightAfterNextRead(OrderStatus $status): void
    {
        $armed = true;
        Order::retrieved(function (Order $retrieved) use (&$armed, $status): void {
            if (! $armed || ! $retrieved->is($this->order)) {
                return;
            }
            $armed = false;
            DB::table('orders')->where('id', $this->order->id)->update(['status' => $status->value]);
        });
    }
}
