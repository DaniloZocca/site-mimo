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
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Ações de status no painel Filament (OrderResource). Elas NÃO passam pelo
 * ProductionWorkflowService (M-24): gravam status + ProductionLog direto e disparam os eventos com listener.
 */
#[Group('characterization')]
class FilamentOrderActionsTest extends CharacterizationTestCase
{
    private const EVENTS = [
        OrderPrintStarted::class,
        OrderShipped::class,
        OrderPrintingStartedEvent::class,
        OrderShippedEvent::class,
        OrderStatusUpdatedEvent::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake(self::EVENTS);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_acoes_de_linha_existentes_na_tabela(): void
    {
        $actions = array_keys(Livewire::test(ListOrders::class)->instance()->getTable()->getFlatActions());
        sort($actions);

        // Cancelar e marcar como entregue entraram no M-10.
        $this->assertSame(['cancelOrder', 'edit', 'markAsDelivered', 'markAsShipped', 'startPackaging', 'startPrinting', 'view'], $actions);
    }

    /**
     * @return array<string, array{string, array<int, string>}>
     */
    public static function visibility(): array
    {
        return [
            'pending_payment' => ['pending_payment', ['cancelOrder']],
            'in_queue' => ['in_queue', ['startPrinting']],
            'printing' => ['printing', ['startPackaging']],
            'finishing_and_packaging' => ['finishing_and_packaging', ['markAsShipped']],
            'shipped' => ['shipped', ['markAsDelivered']],
            'delivered' => ['delivered', []],
            'cancelled' => ['cancelled', []],
        ];
    }

    /**
     * @param  array<int, string>  $visible
     */
    #[DataProvider('visibility')]
    public function test_visibilidade_das_acoes_de_status(string $status, array $visible): void
    {
        $order = Order::factory()->status(OrderStatus::from($status))->create();
        $component = Livewire::test(ListOrders::class);

        foreach (['startPrinting', 'startPackaging', 'markAsShipped', 'markAsDelivered', 'cancelOrder'] as $action) {
            in_array($action, $visible, true)
                ? $component->assertTableActionVisible($action, $order)
                : $component->assertTableActionHidden($action, $order);
        }
    }

    /**
     * Corrigido #21 (M-10) — antes: gêmeo em KnownBugs/OrderStateMachine/OrderStateMachineKnownBugsTest.
     */
    public function test_admin_iniciar_impressao_dispara_evento_com_listener(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        Livewire::test(ListOrders::class)->callTableAction('startPrinting', $order);

        $this->assertSame(OrderStatus::PRINTING, $order->fresh()->status);
        Event::assertDispatched(OrderPrintingStartedEvent::class);
        Event::assertNotDispatched(OrderPrintStarted::class);
    }

    public function test_acabamento_grava_log_sem_nenhum_evento(): void
    {
        $order = Order::factory()->status(OrderStatus::PRINTING)->create();

        Livewire::test(ListOrders::class)->callTableAction('startPackaging', $order);

        $this->assertSame(OrderStatus::FINISHING_AND_PACKAGING, $order->fresh()->status);
        $this->assertSame(
            'Peça retirada da mesa de impressão, movida para conferência, acabamento e embalagem.',
            $order->productionLogs()->sole()->notes
        );
        // M-24: pelo ProductionWorkflowService dispara só OrderStatusUpdatedEvent (listener só loga), nenhum evento de e-mail
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
        foreach (array_diff(self::EVENTS, [OrderStatusUpdatedEvent::class]) as $event) {
            Event::assertNotDispatched($event);
        }
    }

    /**
     * Corrigido #21 (M-10) — antes: gêmeo em KnownBugs/OrderStateMachine/OrderStateMachineKnownBugsTest.
     */
    public function test_admin_expedir_dispara_evento_com_listener(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('markAsShipped', $order, data: ['carrier_tracking_code' => 'BR123456789BR'])
            ->assertHasNoTableActionErrors();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::SHIPPED, $fresh->status);
        $this->assertSame('BR123456789BR', $fresh->carrier_tracking_code);
        $this->assertSame(
            'Pacote despachado via transportadora com código de rastreio: BR123456789BR',
            $order->productionLogs()->sole()->notes
        );
        Event::assertDispatched(OrderShippedEvent::class, fn (OrderShippedEvent $e): bool => $e->carrierTrackingCode === 'BR123456789BR');
        Event::assertNotDispatched(OrderShipped::class);
    }

    public function test_expedir_exige_codigo_de_rastreio(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('markAsShipped', $order, data: ['carrier_tracking_code' => ''])
            ->assertHasTableActionErrors(['carrier_tracking_code' => 'required']);

        $this->assertSame(OrderStatus::FINISHING_AND_PACKAGING, $order->fresh()->status);
    }

    public function test_editar_pedido_so_altera_o_codigo_de_rastreio_mesmo_em_pendente(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create(['total_cents' => 10000]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->fillForm([
                'carrier_tracking_code' => 'BR000',
                'status' => 'delivered',
                'total_cents' => 1,
                'customer_email' => 'hacker@example.com',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $order->fresh();
        $this->assertSame('BR000', $fresh->carrier_tracking_code);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $fresh->status);
        $this->assertSame(10000, $fresh->total_cents);
        $this->assertSame('fernanda@example.com', $fresh->customer_email);
        $this->assertSame(0, $order->productionLogs()->count());
    }
}
