<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderPrintStarted;
use App\Events\OrderShipped;
use App\Events\OrderShippedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Ações de produção do painel (bug #14): só na sequência in_queue → printing →
 * finishing_and_packaging → shipped, com visibilidade pela regra do ProductionWorkflowService
 * e update condicional no servidor (tela desatualizada / dois operadores).
 */
#[Group('characterization')]
class FilamentProductionActionsGuardTest extends CharacterizationTestCase
{
    private const EVENTS = [
        OrderPrintStarted::class,
        OrderShipped::class,
        OrderPrintingStartedEvent::class,
        OrderShippedEvent::class,
        OrderStatusUpdatedEvent::class,
    ];

    private const STATUS_CHANGED_MESSAGE = 'O status deste pedido mudou. Atualize a página.';

    /** Ação => [status de origem, status de destino]. */
    private const ACTIONS = [
        'startPrinting' => ['in_queue', 'printing'],
        'startPackaging' => ['printing', 'finishing_and_packaging'],
        'markAsShipped' => ['finishing_and_packaging', 'shipped'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake(self::EVENTS);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function actionsByStatus(): array
    {
        $cases = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            foreach (OrderStatus::cases() as $status) {
                $cases["{$action} em {$status->value}"] = [$action, $status->value];
            }
        }

        return $cases;
    }

    #[DataProvider('actionsByStatus')]
    public function test_acao_so_fica_visivel_no_status_de_origem(string $action, string $status): void
    {
        $order = Order::factory()->status(OrderStatus::from($status))->create();
        $component = Livewire::test(ListOrders::class);

        self::ACTIONS[$action][0] === $status
            ? $component->assertTableActionVisible($action, $order)
            : $component->assertTableActionHidden($action, $order);
    }

    public function test_admin_nao_inicia_impressao_de_pedido_nao_pago(): void
    {
        // Gêmeo do bug #14 movido de KnownBugs
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();

        Livewire::test(ListOrders::class)->assertTableActionHidden('startPrinting', $order);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_iniciar_impressao_na_fila_muda_status_grava_log_e_dispara_evento(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('startPrinting', $order)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Extrusão 3D Iniciada!');

        $this->assertSame(OrderStatus::PRINTING, $order->fresh()->status);
        $this->assertStatusChangeLog($order, 'in_queue', 'printing', 'Impressão 3D iniciada na fazenda de manufatura.');
        Event::assertDispatched(OrderPrintingStartedEvent::class, fn (OrderPrintingStartedEvent $e): bool => $e->order->is($order));
    }

    public function test_acabamento_muda_status_e_grava_log_sem_evento(): void
    {
        $order = Order::factory()->status(OrderStatus::PRINTING)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('startPackaging', $order)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Peça Impressa com Sucesso!');

        $this->assertSame(OrderStatus::FINISHING_AND_PACKAGING, $order->fresh()->status);
        $this->assertStatusChangeLog(
            $order,
            'printing',
            'finishing_and_packaging',
            'Peça retirada da mesa de impressão, movida para conferência, acabamento e embalagem.'
        );
        // M-24: pelo ProductionWorkflowService dispara só OrderStatusUpdatedEvent (listener só loga), nenhum evento de e-mail
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
        foreach (array_diff(self::EVENTS, [OrderStatusUpdatedEvent::class]) as $event) {
            Event::assertNotDispatched($event);
        }
    }

    public function test_expedir_muda_status_grava_codigo_log_e_dispara_evento(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('markAsShipped', $order, data: ['carrier_tracking_code' => 'BR123456789BR'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Encomenda Despachada!');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::SHIPPED, $fresh->status);
        $this->assertSame('BR123456789BR', $fresh->carrier_tracking_code);
        $this->assertStatusChangeLog(
            $order,
            'finishing_and_packaging',
            'shipped',
            'Pacote despachado via transportadora com código de rastreio: BR123456789BR'
        );
        Event::assertDispatched(
            OrderShippedEvent::class,
            fn (OrderShippedEvent $e): bool => $e->order->is($order) && $e->carrierTrackingCode === 'BR123456789BR'
        );
    }

    public function test_pedido_em_pagamento_em_revisao_nao_tem_acao_de_producao_e_chamada_forcada_nao_muda_nada(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        ProductionLog::factory()->create([
            'order_id' => $order->id,
            'previous_status' => 'pending_payment',
            'new_status' => 'pending_payment',
            'notes' => '[Sistema] revisão',
            'is_internal' => true,
            'code' => ProductionLog::CODE_PAYMENT_REVIEW,
            'reference' => '123',
        ]);

        $component = Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('payment_review', 'Pagamento em revisão', $order);

        foreach (array_keys(self::ACTIONS) as $action) {
            $component->assertTableActionHidden($action, $order);
            // Requisição forjada: chama os métodos Livewire direto, ignorando o botão oculto
            $component
                ->call('mountTableAction', $action, $order->getKey())
                ->set('mountedTableActionsData.0.carrier_tracking_code', 'BR999')
                ->call('callMountedTableAction');
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $fresh->status);
        $this->assertNull($fresh->carrier_tracking_code);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(1, $order->productionLogs()->count(), 'Só o registro de revisão pré-existente.');
        foreach (self::EVENTS as $event) {
            Event::assertNotDispatched($event);
        }
    }

    public function test_tela_desatualizada_nao_executa_a_acao(): void
    {
        // Página carregada com o pedido na fila; outro operador cancela antes do clique.
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        $component = Livewire::test(ListOrders::class)->mountTableAction('startPrinting', $order);

        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::CANCELLED->value]);

        // O Filament relê o registro a cada requisição: a ação some (camada 1) e não roda.
        $component->callMountedTableAction();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderPrintingStartedEvent::class);
    }

    public function test_corrida_entre_leitura_e_gravacao_nao_sobrescreve_pedido_cancelado(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        $component = Livewire::test(ListOrders::class)->mountTableAction('startPrinting', $order);

        // O pedido é cancelado logo depois que o Filament lê o registro (ainda in_queue em memória).
        $this->cancelRightAfterNextRead($order);
        $component->callMountedTableAction();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderPrintingStartedEvent::class);
        $component->assertNotified(self::STATUS_CHANGED_MESSAGE);
    }

    public function test_expedir_com_transicao_invalida_nao_grava_codigo_de_rastreio(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();
        $component = Livewire::test(ListOrders::class)
            ->mountTableAction('markAsShipped', $order)
            ->setTableActionData(['carrier_tracking_code' => 'BR123456789BR']);

        $this->cancelRightAfterNextRead($order);
        $component->callMountedTableAction();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertNull($fresh->carrier_tracking_code);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderShippedEvent::class);
        $component->assertNotified(self::STATUS_CHANGED_MESSAGE);
    }

    public function test_campo_status_do_formulario_nao_altera_o_status_ao_salvar(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->set('data.status', OrderStatus::IN_QUEUE->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
    }

    private function assertStatusChangeLog(Order $order, string $from, string $to, string $notes): void
    {
        $log = $order->productionLogs()->sole();
        $this->assertSame(
            [$from, $to, $notes, ProductionLog::CODE_STATUS_CHANGE, false],
            [$log->previous_status, $log->new_status, $log->notes, $log->code, $log->is_internal]
        );
    }

    /**
     * Simula um segundo operador: cancela o pedido no banco logo após a próxima leitura dele.
     */
    private function cancelRightAfterNextRead(Order $order): void
    {
        $armed = true;
        Order::retrieved(function (Order $retrieved) use (&$armed, $order): void {
            if (! $armed || ! $retrieved->is($order)) {
                return;
            }
            $armed = false;
            DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::CANCELLED->value]);
        });
    }
}
