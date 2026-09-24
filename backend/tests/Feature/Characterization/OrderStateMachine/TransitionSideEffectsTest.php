<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Services\ProductionWorkflowService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Efeitos colaterais do ProductionWorkflowService além da matriz.
 * Observação: nenhum código de produção chama este serviço hoje (webhook, checkout e
 * Filament mudam o status direto). Estes testes caracterizam o serviço isoladamente.
 */
#[Group('characterization')]
class TransitionSideEffectsTest extends CharacterizationTestCase
{
    private ProductionWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPrintingStartedEvent::class, OrderShippedEvent::class, OrderStatusUpdatedEvent::class]);
        $this->service = app(ProductionWorkflowService::class);
    }

    public function test_evento_de_impressao_carrega_as_notas(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        $this->service->startPrinting($order);

        Event::assertDispatched(OrderPrintingStartedEvent::class, fn ($e): bool => $e->notes === 'Extrusão 3D iniciada na impressora.' && $e->order->is($order));
        $this->assertSame('[Sistema / Operador 3D] Extrusão 3D iniciada na impressora.', $order->productionLogs()->sole()->notes);
    }

    public function test_dispatch_order_grava_codigo_e_evento_de_envio(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        $result = $this->service->dispatchOrder($order, 'BR123456789BR');

        $this->assertSame(OrderStatus::SHIPPED, $result->status);
        $this->assertSame('BR123456789BR', $order->fresh()->carrier_tracking_code);
        $this->assertSame('[Sistema / Operador 3D] Pacote despachado. | Rastreio: BR123456789BR', $order->productionLogs()->sole()->notes);
        Event::assertDispatched(OrderShippedEvent::class, fn ($e): bool => $e->carrierTrackingCode === 'BR123456789BR');
    }

    public function test_notas_padrao_dos_atalhos(): void
    {
        $order = Order::factory()->status(OrderStatus::PRINTING)->create();
        $order = $this->service->moveToFinishing($order);
        $order = $this->service->dispatchOrder($order, 'X1');
        $order = $this->service->markAsDelivered($order);

        $notes = $order->productionLogs()->get()->pluck('notes')->sort()->values()->all();
        $this->assertSame([
            '[Sistema / Operador 3D] Encomenda entregue com sucesso ao cliente.',
            '[Sistema / Operador 3D] Pacote despachado. | Rastreio: X1',
            '[Sistema / Operador 3D] Peça retirada da mesa de impressão. Em acabamento e embalagem.',
        ], $notes);
    }

    public function test_cancel_order_prefixa_o_motivo(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        $this->service->cancelOrder($order, 'cliente desistiu');

        $this->assertSame('[Sistema / Operador 3D] Cancelamento: cliente desistiu', $order->productionLogs()->sole()->notes);
    }

    public function test_autor_nulo_ou_vazio_nao_prefixa_as_notas(): void
    {
        $a = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        $b = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        $this->service->transitionTo($a, OrderStatus::PRINTING, 'sem autor', null);
        $this->service->transitionTo($b, OrderStatus::PRINTING, 'autor vazio', '');

        $this->assertSame('sem autor', $a->productionLogs()->sole()->notes);
        $this->assertSame('autor vazio', $b->productionLogs()->sole()->notes);
    }

    public function test_estados_finais_nao_saem(): void
    {
        foreach ([OrderStatus::DELIVERED, OrderStatus::CANCELLED] as $final) {
            foreach (OrderStatus::cases() as $to) {
                if ($to === $final) {
                    continue;
                }
                $order = Order::factory()->status($final)->create();
                try {
                    $this->service->transitionTo($order, $to);
                    $this->fail("{$final->value} → {$to->value} deveria lançar exceção");
                } catch (InvalidOrderStatusTransitionException) {
                    $this->assertSame($final, $order->fresh()->status);
                }
            }
        }
    }

    public function test_objeto_desatualizado_sobrescreve_pedido_ja_cancelado(): void
    {
        // BUG CONHECIDO: a validação usa o status em memória, não o da linha travada — ver teste gêmeo em KnownBugs
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        Order::whereKey($stale->id)->first()->forceFill(['status' => OrderStatus::CANCELLED])->save();

        $result = $this->service->transitionTo($stale, OrderStatus::PRINTING, 'operador atrasado');

        $this->assertSame(OrderStatus::PRINTING, $result->status);
        $this->assertSame(OrderStatus::PRINTING, $stale->fresh()->status);
        $log = $stale->productionLogs()->sole();
        $this->assertSame('in_queue', $log->previous_status, 'O log registra o status em memória, não o real (cancelled).');
    }

    public function test_dispatch_order_invalido_grava_o_codigo_mesmo_assim(): void
    {
        // BUG CONHECIDO: dispatchOrder salva carrier_tracking_code antes de validar a transição — ver teste gêmeo em KnownBugs
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        try {
            $this->service->dispatchOrder($order, 'BR999');
            $this->fail('Esperava InvalidOrderStatusTransitionException');
        } catch (InvalidOrderStatusTransitionException) {
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::IN_QUEUE, $fresh->status);
        $this->assertSame('BR999', $fresh->carrier_tracking_code);
    }
}
