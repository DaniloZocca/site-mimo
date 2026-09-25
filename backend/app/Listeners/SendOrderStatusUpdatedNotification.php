<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderStatusUpdatedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderStatusUpdatedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(OrderStatusUpdatedEvent $event): void
    {
        Log::info("🔔 [Notificação Assíncrona] Atualização de status da encomenda #{$event->order->order_number} para {$event->newStatus->getLabel()}", [
            'customer_email' => $event->order->customer_email,
            'previous' => $event->previousStatus->value,
            'new' => $event->newStatus->value,
            'notes' => $event->notes,
        ]);

        // Disparo de e-mail / WhatsApp notificando que a peça começou a ser impressa ou foi enviada
    }
}
