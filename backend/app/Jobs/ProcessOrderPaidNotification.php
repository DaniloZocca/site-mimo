<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderPaidNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly Order $order
    ) {}

    public function handle(): void
    {
        Log::info("Processando notificações para a encomenda paga #{$this->order->order_number}", [
            'order_id' => $this->order->id,
            'customer_email' => $this->order->customer_email,
            'total' => $this->order->total_in_cents,
        ]);

        // Aqui podem ser disparados os e-mails transacionais (Resend/Mailgun),
        // mensagens via WhatsApp (Evolution API/Z-API) e alertas da fazenda de impressão.
    }
}
