<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderShippedEvent;
use App\Mail\OrderShippedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderShippedNotification implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    /**
     * Número máximo de tentativas de envio.
     */
    public int $tries = 3;

    /**
     * Backoff exponencial: 30s, 2min, 5min.
     */
    public array $backoff = [30, 120, 300];

    public function handle(OrderShippedEvent $event): void
    {
        $order = $event->order;
        $customerEmail = $order->customer_email;

        if (! $customerEmail) {
            Log::warning('Tentativa de envio de e-mail de despacho abortada: e-mail do cliente não encontrado.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return;
        }

        Mail::to($customerEmail)->send(new OrderShippedMail($order));

        Log::info('E-mail de despacho e código dos Correios enviado com sucesso.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'carrier_tracking_code' => $order->carrier_tracking_code,
        ]);
    }

    public function failed(OrderShippedEvent $event, Throwable $exception): void
    {
        Log::error('FALHA: E-mail de despacho da encomenda não pôde ser entregue.', [
            'order_id' => $event->order->id,
            'order_number' => $event->order->order_number,
            'exception' => $exception::class,
        ]);
    }
}
