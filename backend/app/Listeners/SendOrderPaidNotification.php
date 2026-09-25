<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaidEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderPaidNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(OrderPaidEvent $event): void
    {
        Log::info("📧 [Notificação Assíncrona] Disparando e-mail de confirmação de pagamento para {$event->order->customer_email}", [
            'order_number' => $event->order->order_number,
            'payment_id' => $event->paymentId,
            'total_cents' => $event->order->total_cents,
        ]);

        // Aqui é integrado o Mailer (Resend/Postmark) ou Mensageria WhatsApp
    }
}
