<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionWorkflowService
{
    /**
     * Mapa de Transições Permitidas da Máquina de Estados da Oficina 3D.
     *
     * @var array<string, array<int, OrderStatus>>
     */
    private const ALLOWED_TRANSITIONS = [
        OrderStatus::PENDING_PAYMENT->value => [
            OrderStatus::IN_QUEUE,
            OrderStatus::CANCELLED,
        ],
        OrderStatus::IN_QUEUE->value => [
            OrderStatus::PRINTING,
            OrderStatus::CANCELLED,
        ],
        OrderStatus::PRINTING->value => [
            OrderStatus::FINISHING_AND_PACKAGING,
            OrderStatus::CANCELLED,
        ],
        OrderStatus::FINISHING_AND_PACKAGING->value => [
            OrderStatus::SHIPPED,
            OrderStatus::CANCELLED,
        ],
        OrderStatus::SHIPPED->value => [
            OrderStatus::DELIVERED,
        ],
        OrderStatus::DELIVERED->value => [],
        OrderStatus::CANCELLED->value => [],
    ];

    /**
     * Consulta pura: a máquina de estados permite ir de $from para $to?
     */
    public static function allows(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * Executa a transição de status atômica com validação formal da máquina de estados.
     *
     * A decisão usa o status gravado no banco (não o do objeto recebido) e a gravação é um UPDATE
     * condicional (WHERE status = status lido), que funciona igual no Postgres e no SQLite.
     * Em todos os resultados a instância $order recebida é atualizada com o estado do banco.
     *
     * @throws InvalidOrderStatusTransitionException
     * @throws Exception
     */
    public function transitionTo(
        Order $order,
        OrderStatus $newStatus,
        string $notes = '',
        ?string $author = 'Sistema / Operador 3D'
    ): Order {
        $this->applyTransition($order, $newStatus, $notes, $author);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $extra  colunas gravadas no mesmo UPDATE do status
     * @return bool true se a transição foi gravada; false se o pedido já estava no status pedido (no-op)
     *
     * @throws InvalidOrderStatusTransitionException
     */
    private function applyTransition(Order $order, OrderStatus $newStatus, string $notes, ?string $author, array $extra = []): bool
    {
        $auditNote = ! empty($author) ? "[{$author}] {$notes}" : $notes;

        /** @var array{0: string, 1: OrderStatus} $outcome */
        $outcome = DB::transaction(function () use ($order, $newStatus, $notes, $auditNote, $extra): array {
            /** @var Order $current */
            $current = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $currentStatus = $current->status;

            if ($currentStatus === $newStatus) {
                return ['noop', $currentStatus];
            }

            if (! self::allows($currentStatus, $newStatus)) {
                return ['invalid', $currentStatus];
            }

            $affected = Order::whereKey($order->getKey())
                ->where('status', $currentStatus->value)
                ->update(['status' => $newStatus->value, 'updated_at' => now()] + $extra);

            if ($affected === 0) {
                // Outro processo mudou o pedido entre a leitura e a gravação: decide pelo status novo
                $racedStatus = Order::whereKey($order->getKey())->firstOrFail()->status;

                return [$racedStatus === $newStatus ? 'noop' : 'invalid', $racedStatus];
            }

            $order->productionLogs()->create([
                'previous_status' => $currentStatus->value,
                'new_status' => $newStatus->value,
                'notes' => $auditNote,
                'created_at' => now(),
            ]);

            // Os eventos levam o estado gravado (status e código de rastreio)
            $order->refresh();

            // Eventos só depois do commit da transação mais externa: num rollback, nada é disparado
            DB::afterCommit(function () use ($order, $currentStatus, $newStatus, $notes): void {
                Log::info("🔄 Status do pedido #{$order->order_number} atualizado com sucesso: '{$currentStatus->value}' -> '{$newStatus->value}'", [
                    'order_id' => $order->id,
                    'notes' => $notes,
                ]);

                event(new OrderStatusUpdatedEvent($order, $currentStatus, $newStatus, $notes));

                if ($newStatus === OrderStatus::PRINTING) {
                    event(new OrderPrintingStartedEvent($order, $notes));
                } elseif ($newStatus === OrderStatus::SHIPPED) {
                    event(new OrderShippedEvent($order, $order->carrier_tracking_code ?? ''));
                }
            });

            return ['applied', $currentStatus];
        });

        [$result, $statusInDatabase] = $outcome;

        $order->refresh();

        if ($result === 'noop') {
            Log::info("Transição idempotente ignorada para o pedido #{$order->order_number}: já se encontra em '{$newStatus->value}'.");

            return false;
        }

        if ($result === 'invalid') {
            Log::warning("Tentativa de transição de status ilegal no pedido #{$order->order_number}: '{$statusInDatabase->value}' -> '{$newStatus->value}'");
            throw new InvalidOrderStatusTransitionException($statusInDatabase, $newStatus);
        }

        return true;
    }

    /**
     * Inicia a manufatura 3D na fazenda de impressão.
     */
    public function startPrinting(Order $order, string $notes = 'Extrusão 3D iniciada na impressora.'): Order
    {
        return $this->transitionTo($order, OrderStatus::PRINTING, $notes);
    }

    /**
     * Move para a bancada de acabamento e mesa de embalagem.
     */
    public function moveToFinishing(Order $order, string $notes = 'Peça retirada da mesa de impressão. Em acabamento e embalagem.'): Order
    {
        return $this->transitionTo($order, OrderStatus::FINISHING_AND_PACKAGING, $notes);
    }

    /**
     * Despacha o pacote e registra o código de rastreio da transportadora.
     */
    public function dispatchOrder(Order $order, string $carrierTrackingCode, string $notes = 'Pacote despachado.'): Order
    {
        $formattedNotes = "{$notes} | Rastreio: {$carrierTrackingCode}";

        // O código entra no mesmo UPDATE do status: transição recusada não grava nada
        $applied = $this->applyTransition(
            $order,
            OrderStatus::SHIPPED,
            $formattedNotes,
            'Sistema / Operador 3D',
            ['carrier_tracking_code' => $carrierTrackingCode],
        );

        if (! $applied && $order->carrier_tracking_code !== $carrierTrackingCode) {
            Log::warning("Pedido #{$order->order_number} já expedido: o código de rastreio informado é diferente do gravado e não foi trocado.");
        }

        return $order;
    }

    /**
     * Confirma a entrega da encomenda ao destinatário final.
     */
    public function markAsDelivered(Order $order, string $notes = 'Encomenda entregue com sucesso ao cliente.'): Order
    {
        return $this->transitionTo($order, OrderStatus::DELIVERED, $notes);
    }

    /**
     * Cancela o pedido com justificativa auditável.
     */
    public function cancelOrder(Order $order, string $reason): Order
    {
        return $this->transitionTo($order, OrderStatus::CANCELLED, "Cancelamento: {$reason}");
    }
}
