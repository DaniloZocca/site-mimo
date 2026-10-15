<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\User;
use App\Services\ProductionWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-24 (regra 5): checkout, webhook e painel mudam o status pelo ProductionWorkflowService::transitionFrom(),
 * que só grava se o pedido ainda estiver na origem esperada por quem chama.
 */
#[Group('characterization')]
class StatusViaWorkflowServiceTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private const STATUS_CHANGED_MESSAGE = 'O status deste pedido mudou. Atualize a página.';

    private ProductionWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderStatusUpdatedEvent::class, OrderPrintingStartedEvent::class, OrderShippedEvent::class]);
        $this->workflow = app(ProductionWorkflowService::class);
    }

    public function test_transition_from_com_a_origem_certa_grava_extras_log_e_evento(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();

        $applied = $this->workflow->transitionFrom(
            $order,
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::IN_QUEUE,
            'Pagamento aprovado (ID: 777)',
            extra: ['paid_at' => now()],
            log: ['code' => ProductionLog::CODE_PAYMENT_APPROVED, 'reference' => '777'],
        );

        $this->assertTrue($applied);
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status, 'A instância de quem chamou reflete o banco.');
        $this->assertNotNull($order->fresh()->paid_at);
        $log = $order->productionLogs()->sole();
        $this->assertSame('pending_payment', $log->previous_status);
        $this->assertSame('in_queue', $log->new_status);
        $this->assertSame('Pagamento aprovado (ID: 777)', $log->notes, 'Sem prefixo de autor.');
        $this->assertSame(ProductionLog::CODE_PAYMENT_APPROVED, $log->code);
        $this->assertSame('777', $log->reference);
        $this->assertFalse($log->is_internal);
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
    }

    public function test_transition_from_sem_campos_de_log_grava_status_change(): void
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create();

        $this->assertTrue($this->workflow->transitionFrom($order, OrderStatus::SHIPPED, OrderStatus::DELIVERED, 'Entregue.'));

        $log = $order->productionLogs()->sole();
        $this->assertSame(ProductionLog::CODE_STATUS_CHANGE, $log->code);
        $this->assertNull($log->reference);
        $this->assertFalse($log->is_internal);
    }

    public function test_pedido_pago_nao_e_cancelado_por_quem_esperava_pedido_pendente(): void
    {
        // in_queue → cancelled é permitido no mapa, mas quem chama esperava pending_payment
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::IN_QUEUE->value]);

        $applied = $this->workflow->transitionFrom($order, OrderStatus::PENDING_PAYMENT, OrderStatus::CANCELLED, 'Cancelamento');

        $this->assertFalse($applied);
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status, 'A instância de quem chamou reflete o banco.');
        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
    }

    public function test_condicao_extra_falsa_nao_grava_nada(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();

        $applied = $this->workflow->transitionFrom(
            $order,
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::CANCELLED,
            'Cancelamento',
            guard: fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
        );

        $this->assertFalse($applied);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
    }

    public function test_transicao_fora_do_mapa_lanca_excecao_sem_gravar(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();

        try {
            $this->workflow->transitionFrom($order, OrderStatus::PENDING_PAYMENT, OrderStatus::SHIPPED, 'x');
            $this->fail('Esperava InvalidOrderStatusTransitionException.');
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
    }

    public function test_painel_nao_cancela_pedido_que_o_webhook_pagou_depois_que_a_tela_abriu(): void
    {
        $this->actAsPanelAdmin();
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $component = Livewire::test(ListOrders::class)
            ->mountTableAction('cancelOrder', $order)
            ->setTableActionData(['reason' => 'Cliente desistiu']);

        // O webhook aprova logo depois que o Filament lê o registro (ainda pendente em memória)
        $this->changeStatusRightAfterNextRead($order, OrderStatus::IN_QUEUE);
        $component->callMountedTableAction();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
        $component->assertNotified(self::STATUS_CHANGED_MESSAGE);
    }

    public function test_painel_iniciar_impressao_e_expedir_disparam_cada_evento_de_email_uma_vez(): void
    {
        $this->actAsPanelAdmin();
        $printing = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        $shipping = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('startPrinting', $printing)
            ->callTableAction('markAsShipped', $shipping, data: ['carrier_tracking_code' => 'BR123456789BR'])
            ->assertHasNoTableActionErrors();

        Event::assertDispatchedTimes(OrderPrintingStartedEvent::class, 1);
        Event::assertDispatchedTimes(OrderShippedEvent::class, 1);
        Event::assertDispatched(OrderShippedEvent::class, fn (OrderShippedEvent $e): bool => $e->order->is($shipping)
            && $e->carrierTrackingCode === 'BR123456789BR');
        $this->assertSame(ProductionLog::CODE_STATUS_CHANGE, $printing->productionLogs()->sole()->code);
        $this->assertSame('Impressão 3D iniciada na fazenda de manufatura.', $printing->productionLogs()->sole()->notes);
    }

    public function test_webhook_aprovado_grava_pelo_servico_e_dispara_evento_de_status(): void
    {
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'in_queue');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::IN_QUEUE, $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $log = $order->productionLogs()->sole();
        $this->assertSame(ProductionLog::CODE_PAYMENT_APPROVED, $log->code);
        $this->assertSame('123', $log->reference);
        $this->assertSame('Pagamento aprovado via Webhook Mercado Pago (Payment ID: 123)', $log->notes);
        Event::assertDispatched(OrderStatusUpdatedEvent::class, fn (OrderStatusUpdatedEvent $e): bool => $e->order->is($order)
            && $e->previousStatus === OrderStatus::PENDING_PAYMENT
            && $e->newStatus === OrderStatus::IN_QUEUE);
    }

    public function test_webhook_recusa_cancela_pelo_servico(): void
    {
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'rejected', $order);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $log = $order->productionLogs()->sole();
        $this->assertSame('Status de gateway recebido: rejected', $log->notes);
        $this->assertSame(ProductionLog::CODE_STATUS_CHANGE, $log->code);
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
    }

    public function test_estorno_atrasado_nao_cancela_pedido_ja_na_fila(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'refunded', $order);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
    }

    public function test_log_do_servico_nao_leva_as_notas_da_transicao(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $messages = [];
        Log::listen(function ($event) use (&$messages): void {
            $messages[] = $event->message.' '.json_encode($event->context);
        });

        $this->workflow->transitionFrom($order, OrderStatus::PENDING_PAYMENT, OrderStatus::CANCELLED, 'Motivo: texto-livre-do-operador');

        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('texto-livre-do-operador', $message);
        }
    }

    public function test_chamada_do_runbook_de_revisao_libera_com_registro_publico_e_interno(): void
    {
        // Mesma chamada de docs/runbook-liberar-pedido-em-revisao.md
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $paymentId = '999';

        DB::transaction(function () use ($order, $paymentId): void {
            $updated = app(ProductionWorkflowService::class)->transitionFrom(
                $order,
                OrderStatus::PENDING_PAYMENT,
                OrderStatus::IN_QUEUE,
                'Pagamento confirmado. Seu pedido entrou na fila de impressão.',
                extra: ['paid_at' => now()],
                log: ['code' => ProductionLog::CODE_PAYMENT_APPROVED, 'reference' => $paymentId],
            );
            $this->assertTrue($updated);

            $order->productionLogs()->create([
                'previous_status' => OrderStatus::PENDING_PAYMENT->value,
                'new_status' => OrderStatus::IN_QUEUE->value,
                'notes' => "Pagamento {$paymentId} conferido manualmente por Operador.",
                'code' => 'payment_manual_release',
                'reference' => $paymentId,
                'is_internal' => true,
                'created_at' => now(),
            ]);
        });

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::IN_QUEUE, $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $public = $order->productionLogs()->public()->sole();
        $this->assertSame(ProductionLog::CODE_PAYMENT_APPROVED, $public->code);
        $this->assertSame('999', $public->reference);
        $this->assertSame('Pagamento confirmado. Seu pedido entrou na fila de impressão.', $public->notes);
        $this->assertSame(1, $order->productionLogs()->where('is_internal', true)->count());
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
    }

    private function actAsPanelAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Muda o status no banco logo depois da próxima leitura do pedido (simula outro processo).
     */
    private function changeStatusRightAfterNextRead(Order $order, OrderStatus $status): void
    {
        $armed = true;
        Order::retrieved(function (Order $retrieved) use (&$armed, $order, $status): void {
            if (! $armed || ! $retrieved->is($order)) {
                return;
            }
            $armed = false;
            DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);
        });
    }
}
