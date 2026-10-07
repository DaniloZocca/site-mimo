<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\ProductionWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Comportamento CORRETO esperado da máquina de estados, do painel e das notificações. FALHAM hoje.
 */
#[Group('known-bugs')]
class OrderStateMachineKnownBugsTest extends CharacterizationTestCase
{
    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * P1 — gêmeo de TransitionSideEffectsTest::test_objeto_desatualizado_sobrescreve_pedido_ja_cancelado
     */
    public function test_transicao_valida_contra_o_status_real_do_banco(): void
    {
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        Order::whereKey($stale->id)->first()->forceFill(['status' => OrderStatus::CANCELLED])->save();

        try {
            app(ProductionWorkflowService::class)->transitionTo($stale, OrderStatus::PRINTING);
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertSame(OrderStatus::CANCELLED, $stale->fresh()->status);
    }

    /**
     * P2 — gêmeo de TransitionSideEffectsTest::test_dispatch_order_invalido_grava_o_codigo_mesmo_assim
     */
    public function test_dispatch_order_invalido_nao_grava_o_codigo(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        try {
            app(ProductionWorkflowService::class)->dispatchOrder($order, 'BR999');
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertNull($order->fresh()->carrier_tracking_code);
    }

    /**
     * P2 — gêmeo de FilamentOrderActionsTest::test_iniciar_impressao_da_fila_dispara_order_print_started_sem_listener
     */
    public function test_admin_iniciar_impressao_dispara_evento_com_listener(): void
    {
        Event::fake([OrderPrintingStartedEvent::class]);
        $this->asAdmin();
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        Livewire::test(ListOrders::class)->callTableAction('startPrinting', $order);

        Event::assertDispatched(OrderPrintingStartedEvent::class);
    }

    /**
     * P2 — gêmeo de FilamentOrderActionsTest::test_expedir_grava_codigo_log_e_order_shipped_sem_listener
     */
    public function test_admin_expedir_dispara_evento_com_listener(): void
    {
        Event::fake([OrderShippedEvent::class]);
        $this->asAdmin();
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        Livewire::test(ListOrders::class)->callTableAction('markAsShipped', $order, data: ['carrier_tracking_code' => 'BR1']);

        Event::assertDispatched(OrderShippedEvent::class);
    }
}
