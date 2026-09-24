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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Todos os 49 pares (de → para) de ProductionWorkflowService::transitionTo().
 * A tabela abaixo é o comportamento OBSERVADO, escrito à mão (não lê ALLOWED_TRANSITIONS),
 * para que qualquer mudança no mapa quebre um caso aqui.
 *
 * Resultados: 'aceita' (muda status + log + eventos), 'no-op' (mesmo status: retorna sem log
 * nem evento), 'exceção' (InvalidOrderStatusTransitionException, nada muda).
 */
#[Group('characterization')]
class TransitionMatrixTest extends CharacterizationTestCase
{
    private const DOMAIN_EVENTS = [
        OrderStatusUpdatedEvent::class,
        OrderPrintingStartedEvent::class,
        OrderShippedEvent::class,
    ];

    private const ACCEPTED = [
        'pending_payment' => ['in_queue', 'cancelled'],
        'in_queue' => ['printing', 'cancelled'],
        'printing' => ['finishing_and_packaging', 'cancelled'],
        'finishing_and_packaging' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function allPairs(): array
    {
        $cases = [];
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $outcome = match (true) {
                    $from === $to => 'no-op',
                    in_array($to->value, self::ACCEPTED[$from->value], true) => 'aceita',
                    default => 'exceção',
                };
                $cases["{$from->value} → {$to->value} ({$outcome})"] = [$from->value, $to->value, $outcome];
            }
        }

        return $cases;
    }

    public function test_a_matriz_tem_49_casos_9_aceitas_7_no_op_33_excecoes(): void
    {
        $counts = array_count_values(array_column(self::allPairs(), 2));
        ksort($counts);

        $this->assertSame(['aceita' => 9, 'exceção' => 33, 'no-op' => 7], $counts);
    }

    #[DataProvider('allPairs')]
    public function test_transicao(string $from, string $to, string $outcome): void
    {
        Event::fake(self::DOMAIN_EVENTS);
        $order = Order::factory()->status(OrderStatus::from($from))->create();
        $service = app(ProductionWorkflowService::class);

        if ($outcome === 'exceção') {
            try {
                $service->transitionTo($order, OrderStatus::from($to), 'nota');
                $this->fail("Esperava InvalidOrderStatusTransitionException em {$from} → {$to}");
            } catch (InvalidOrderStatusTransitionException $e) {
                $fromEnum = OrderStatus::from($from);
                $toEnum = OrderStatus::from($to);
                $this->assertSame(
                    "Transição de status inválida no fluxo produtivo 3D: '{$fromEnum->getLabel()}' ({$from}) para '{$toEnum->getLabel()}' ({$to}).",
                    $e->getMessage()
                );
            }

            $this->assertSame($from, $order->fresh()->status->value);
            $this->assertSame(0, $order->productionLogs()->count());
            $this->assertNoDomainEvents();

            return;
        }

        $result = $service->transitionTo($order, OrderStatus::from($to), 'nota');

        if ($outcome === 'no-op') {
            $this->assertSame($order, $result, 'No-op devolve a MESMA instância recebida.');
            $this->assertSame($from, $order->fresh()->status->value);
            $this->assertSame(0, $order->productionLogs()->count());
            $this->assertNoDomainEvents();

            return;
        }

        // aceita
        $this->assertNotSame($order, $result, 'Transição aceita devolve uma NOVA instância (a recebida fica desatualizada).');
        $this->assertSame($from, $order->status->value);
        $this->assertSame($to, $result->status->value);
        $this->assertSame($to, $order->fresh()->status->value);

        $log = $order->productionLogs()->sole();
        $this->assertSame($from, $log->previous_status);
        $this->assertSame($to, $log->new_status);
        $this->assertSame('[Sistema / Operador 3D] nota', $log->notes);
        $this->assertSame(self::FROZEN_NOW, $log->created_at->format('Y-m-d H:i:s'));

        Event::assertDispatched(OrderStatusUpdatedEvent::class, fn (OrderStatusUpdatedEvent $e): bool => $e->previousStatus->value === $from
            && $e->newStatus->value === $to
            && $e->notes === 'nota'
            && $e->order->is($order));
        Event::assertDispatchedTimes(OrderPrintingStartedEvent::class, $to === 'printing' ? 1 : 0);
        Event::assertDispatchedTimes(OrderShippedEvent::class, $to === 'shipped' ? 1 : 0);
    }

    private function assertNoDomainEvents(): void
    {
        foreach (self::DOMAIN_EVENTS as $event) {
            Event::assertNotDispatched($event);
        }
    }
}
