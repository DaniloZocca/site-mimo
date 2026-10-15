<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Services\ProductionWorkflowService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

    public function test_transicao_valida_contra_o_status_real_do_banco(): void
    {
        // Bug #15 corrigido (M-16): era OrderStateMachineKnownBugsTest
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        Order::whereKey($stale->id)->first()->forceFill(['status' => OrderStatus::CANCELLED])->save();

        try {
            app(ProductionWorkflowService::class)->transitionTo($stale, OrderStatus::PRINTING);
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertSame(OrderStatus::CANCELLED, $stale->fresh()->status);
    }

    public function test_dispatch_order_invalido_nao_grava_o_codigo(): void
    {
        // Bug #22 corrigido (M-16): era OrderStateMachineKnownBugsTest
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        try {
            app(ProductionWorkflowService::class)->dispatchOrder($order, 'BR999');
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertNull($order->fresh()->carrier_tracking_code);
    }

    public function test_objeto_desatualizado_com_pedido_cancelado_cita_o_status_real_e_atualiza_a_instancia(): void
    {
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        DB::table('orders')->where('id', $stale->id)->update(['status' => OrderStatus::CANCELLED->value]);

        try {
            $this->service->transitionTo($stale, OrderStatus::PRINTING, 'operador atrasado');
            $this->fail('Esperava InvalidOrderStatusTransitionException');
        } catch (InvalidOrderStatusTransitionException $e) {
            $this->assertStringContainsString("'Cancelado' (cancelled)", $e->getMessage());
        }

        $this->assertSame(OrderStatus::CANCELLED, $stale->status, 'A instância recebida reflete o status real.');
        $this->assertSame(0, $stale->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
        Event::assertNotDispatched(OrderPrintingStartedEvent::class);
    }

    public function test_objeto_desatualizado_usa_o_status_real_como_origem(): void
    {
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        DB::table('orders')->where('id', $stale->id)->update(['status' => OrderStatus::PRINTING->value]);

        $result = $this->service->transitionTo($stale, OrderStatus::FINISHING_AND_PACKAGING);

        $this->assertSame($stale, $result);
        $this->assertSame(OrderStatus::FINISHING_AND_PACKAGING, $stale->status);
        $this->assertSame('printing', $stale->productionLogs()->sole()->previous_status);
        Event::assertDispatched(OrderStatusUpdatedEvent::class, fn ($e): bool => $e->previousStatus === OrderStatus::PRINTING);
    }

    public function test_objeto_desatualizado_pedindo_o_status_que_o_banco_ja_tem_e_no_op(): void
    {
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        DB::table('orders')->where('id', $stale->id)->update(['status' => OrderStatus::PRINTING->value]);

        $result = $this->service->transitionTo($stale, OrderStatus::PRINTING);

        $this->assertSame($stale, $result);
        $this->assertSame(OrderStatus::PRINTING, $stale->status);
        $this->assertSame(0, $stale->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
        Event::assertNotDispatched(OrderPrintingStartedEvent::class);
    }

    public function test_pedido_cancelado_entre_a_leitura_e_a_gravacao_nao_e_sobrescrito(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        $this->runRightAfterNextRead($order, fn () => DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::CANCELLED->value]));

        try {
            $this->service->transitionTo($order, OrderStatus::PRINTING);
            $this->fail('Esperava InvalidOrderStatusTransitionException');
        } catch (InvalidOrderStatusTransitionException $e) {
            $this->assertStringContainsString("'Cancelado' (cancelled)", $e->getMessage());
        }

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
        Event::assertNotDispatched(OrderPrintingStartedEvent::class);
    }

    public function test_dois_processos_com_o_mesmo_destino_geram_uma_transicao_sem_erro(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $other = Order::findOrFail($order->id);
        // O segundo processo faz a mesma transição entre a leitura e a gravação do primeiro
        $this->runRightAfterNextRead($order, fn () => app(ProductionWorkflowService::class)->transitionTo($other, OrderStatus::IN_QUEUE));

        $result = $this->service->transitionTo($order, OrderStatus::IN_QUEUE);

        $this->assertSame($order, $result);
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);
        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(1, $order->productionLogs()->count());
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
    }

    public function test_rollback_da_transacao_externa_nao_dispara_eventos_nem_grava_log(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        try {
            DB::transaction(function () use ($order): void {
                $this->service->dispatchOrder($order, 'BR123456789BR');
                throw new \RuntimeException('falha depois da transição');
            });
        } catch (\RuntimeException) {
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::FINISHING_AND_PACKAGING, $fresh->status);
        $this->assertNull($fresh->carrier_tracking_code);
        $this->assertSame(0, ProductionLog::where('order_id', $order->id)->count());
        Event::assertNotDispatched(OrderStatusUpdatedEvent::class);
        Event::assertNotDispatched(OrderShippedEvent::class);
    }

    public function test_eventos_esperam_o_commit_da_transacao_externa(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        DB::transaction(function () use ($order): void {
            $this->service->startPrinting($order);
            Event::assertNotDispatched(OrderPrintingStartedEvent::class);
        });

        Event::assertDispatchedTimes(OrderPrintingStartedEvent::class, 1);
        Event::assertDispatchedTimes(OrderStatusUpdatedEvent::class, 1);
    }

    public function test_dispatch_order_com_pedido_cancelado_entre_a_leitura_e_a_gravacao_nao_grava_o_codigo(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();
        $this->runRightAfterNextRead($order, fn () => DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::CANCELLED->value]));

        try {
            $this->service->dispatchOrder($order, 'BR123456789BR');
            $this->fail('Esperava InvalidOrderStatusTransitionException');
        } catch (InvalidOrderStatusTransitionException) {
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertNull($fresh->carrier_tracking_code);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderShippedEvent::class);
    }

    public function test_dispatch_order_em_pedido_ja_expedido_preserva_o_codigo_e_avisa_no_log(): void
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create(['carrier_tracking_code' => 'BR111']);
        Log::spy();

        $result = $this->service->dispatchOrder($order, 'BR222');

        $this->assertSame(OrderStatus::SHIPPED, $result->status);
        $this->assertSame('BR111', $order->fresh()->carrier_tracking_code);
        $this->assertSame(0, $order->productionLogs()->count());
        Event::assertNotDispatched(OrderShippedEvent::class);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, $order->order_number)
                && ! str_contains($message, 'BR111')
                && ! str_contains($message, 'BR222'),
        );
    }

    public function test_dispatch_order_em_pedido_ja_expedido_com_o_mesmo_codigo_nao_avisa(): void
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create(['carrier_tracking_code' => 'BR111']);
        Log::spy();

        $this->service->dispatchOrder($order, 'BR111');

        Log::shouldNotHaveReceived('warning');
        $this->assertSame(0, $order->productionLogs()->count());
    }

    private function runRightAfterNextRead(Order $order, Closure $callback): void
    {
        $armed = true;
        Order::retrieved(function (Order $retrieved) use (&$armed, $order, $callback): void {
            if (! $armed || ! $retrieved->is($order)) {
                return;
            }
            $armed = false;
            $callback();
        });
    }
}
