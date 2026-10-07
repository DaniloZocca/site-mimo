<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Services\ProductionWorkflowService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Comportamento CORRETO esperado da máquina de estados. FALHAM hoje.
 */
#[Group('known-bugs')]
class OrderStateMachineKnownBugsTest extends CharacterizationTestCase
{
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
}
