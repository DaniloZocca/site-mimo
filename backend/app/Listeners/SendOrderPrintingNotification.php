<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPrintingStartedEvent;
use App\Mail\OrderPrintingStartedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderPrintingNotification implements ShouldQueueAfterCommit
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

    public function handle(OrderPrintingStartedEvent $event): void
    {
        $order = $event->order;
        $customerEmail = $order->customer_email;

        if (! $customerEmail) {
            Log::warning('Tentativa de envio de e-mail de impressão abortada: e-mail do cliente não encontrado.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return;
        }

        Mail::to($customerEmail)->send(new OrderPrintingStartedMail($order));

        Log::info('E-mail de início de extrusão 3D enviado com sucesso.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);
    }

    public function failed(OrderPrintingStartedEvent $event, Throwable $exception): void
    {
        Log::error('FALHA: E-mail de início de impressão 3D não pôde ser entregue.', [
            'order_id' => $event->order->id,
            'order_number' => $event->order->order_number,
            'exception' => $exception::class,
        ]);
    }
}
