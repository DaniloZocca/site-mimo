<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Painel: badge e filtro "Pagamento em revisão" (pedido pendente/cancelado com registro interno
 * de divergência ou de pagamento após cancelamento). Nenhuma ação nova.
 */
#[Group('characterization')]
class FilamentPaymentReviewTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function orderWithReviewLog(OrderStatus $status, string $code): Order
    {
        $order = Order::factory()->status($status)->create();
        ProductionLog::factory()->create([
            'order_id' => $order->id,
            'previous_status' => $status->value,
            'new_status' => $status->value,
            'notes' => '[Sistema] revisão',
            'is_internal' => true,
            'code' => $code,
            'reference' => '123',
        ]);

        return $order;
    }

    public function test_filtro_lista_so_pedidos_pendentes_ou_cancelados_com_registro_de_revisao(): void
    {
        $divergent = $this->orderWithReviewLog(OrderStatus::PENDING_PAYMENT, ProductionLog::CODE_PAYMENT_REVIEW);
        $afterCancel = $this->orderWithReviewLog(OrderStatus::CANCELLED, ProductionLog::CODE_PAYMENT_AFTER_CANCELLATION);
        // Liberado depois da revisão: não aparece mais
        $released = $this->orderWithReviewLog(OrderStatus::IN_QUEUE, ProductionLog::CODE_PAYMENT_REVIEW);
        $plainPending = Order::factory()->create();
        $cancelledWithoutReview = Order::factory()->status(OrderStatus::CANCELLED)->create();

        Livewire::test(ListOrders::class)
            ->filterTable('payment_review')
            ->assertCanSeeTableRecords([$divergent, $afterCancel])
            ->assertCanNotSeeTableRecords([$released, $plainPending, $cancelledWithoutReview]);
    }

    public function test_coluna_mostra_badge_so_para_pedido_em_revisao(): void
    {
        $divergent = $this->orderWithReviewLog(OrderStatus::PENDING_PAYMENT, ProductionLog::CODE_PAYMENT_REVIEW);
        $released = $this->orderWithReviewLog(OrderStatus::IN_QUEUE, ProductionLog::CODE_PAYMENT_REVIEW);
        $plainPending = Order::factory()->create();

        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('payment_review', 'Pagamento em revisão', $divergent)
            ->assertTableColumnStateNotSet('payment_review', 'Pagamento em revisão', $released)
            ->assertTableColumnStateNotSet('payment_review', 'Pagamento em revisão', $plainPending);
    }

    public function test_log_interno_sem_codigo_de_revisao_nao_marca_o_pedido(): void
    {
        $order = $this->orderWithReviewLog(OrderStatus::PENDING_PAYMENT, 'outro_codigo');

        $this->assertFalse($order->isInPaymentReview());
        $this->assertSame(0, Order::inPaymentReview()->count());
    }
}
