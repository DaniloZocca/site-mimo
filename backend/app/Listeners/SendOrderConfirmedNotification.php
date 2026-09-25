<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaidEvent;
use App\Mail\OrderConfirmedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderConfirmedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Número máximo de tentativas de envio em caso de oscilação do servidor de e-mail.
     */
    public int $tries = 3;

    /**
     * Intervalo de retentativa exponencial (segundos): 30s, 2min, 5min.
     */
    public array $backoff = [30, 120, 300];

    public function handle(OrderPaidEvent $event): void
    {
        $order = $event->order;
        $order->loadMissing(['customer', 'orderItems']);

        $customerEmail = $order->customer?->email;

        if (! $customerEmail) {
            Log::warning('Tentativa de envio de e-mail de confirmação abortada: e-mail do cliente não encontrado.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return;
        }

        Mail::to($customerEmail)->send(new OrderConfirmedMail($order));

        Log::info('E-mail de confirmação de encomenda enviado com sucesso.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'recipient' => $customerEmail,
        ]);
    }

    /**
     * Tratamento defensivo caso todas as tentativas de envio na fila falhem.
     */
    public function failed(OrderPaidEvent $event, Throwable $exception): void
    {
        Log::error('FALHA CRÍTICA: E-mail de confirmação de encomenda não pôde ser entregue após múltiplas tentativas.', [
            'order_id' => $event->order->id,
            'order_number' => $event->order->order_number,
            'error_message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
