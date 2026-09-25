<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
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
     * @throws InvalidOrderStatusTransitionException
     * @throws Exception
     */
    public function transitionTo(
        Order $order,
        OrderStatus $newStatus,
        string $notes = '',
        ?string $author = 'Sistema / Operador 3D'
    ): Order {
        $currentStatus = $order->status;

        // Se o status já for o desejado, operação é idempotente
        if ($currentStatus === $newStatus) {
            Log::info("Transição idempotente ignorada para o pedido #{$order->order_number}: já se encontra em '{$newStatus->value}'.");
            return $order;
        }

        // Validação da Máquina de Estados (Impedir transições ilegais)
        $allowedNext = self::ALLOWED_TRANSITIONS[$currentStatus->value] ?? [];
        if (! in_array($newStatus, $allowedNext, true)) {
            Log::warning("Tentativa de transição de status ilegal no pedido #{$order->order_number}: '{$currentStatus->value}' -> '{$newStatus->value}'");
            throw new InvalidOrderStatusTransitionException($currentStatus, $newStatus);
        }

        // Execução atômica com Bloqueio Pessimista (lockForUpdate)
        $updatedOrder = DB::transaction(function () use ($order, $currentStatus, $newStatus, $notes, $author): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $lockedOrder->status = $newStatus;
            $lockedOrder->save();

            $auditNote = ! empty($author) ? "[{$author}] {$notes}" : $notes;

            $lockedOrder->productionLogs()->create([
                'previous_status' => $currentStatus->value,
                'new_status'      => $newStatus->value,
                'notes'           => $auditNote,
                'created_at'      => now(),
            ]);

            return $lockedOrder;
        });

        Log::info("🔄 Status do pedido #{$updatedOrder->order_number} atualizado com sucesso: '{$currentStatus->value}' -> '{$newStatus->value}'", [
            'order_id'   => $updatedOrder->id,
            'notes'      => $notes,
        ]);

        // Dispara evento genérico de atualização
        event(new OrderStatusUpdatedEvent($updatedOrder, $currentStatus, $newStatus, $notes));

        // Disparo de eventos específicos de ciclo de vida para notificações transacionais
        if ($newStatus === OrderStatus::PRINTING) {
            event(new \App\Events\OrderPrintingStartedEvent($updatedOrder, $notes));
        } elseif ($newStatus === OrderStatus::SHIPPED) {
            event(new \App\Events\OrderShippedEvent($updatedOrder, $updatedOrder->carrier_tracking_code ?? ''));
        }

        return $updatedOrder;
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
        $order->carrier_tracking_code = $carrierTrackingCode;
        $order->save();

        $formattedNotes = "{$notes} | Rastreio: {$carrierTrackingCode}";
        return $this->transitionTo($order, OrderStatus::SHIPPED, $formattedNotes);
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
