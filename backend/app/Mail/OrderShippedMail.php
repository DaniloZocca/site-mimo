<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderShippedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order
    ) {
        $this->order->loadMissing(['orderItems', 'customer']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "📦 Sua peça MIMOO LAB (#{$this->order->order_number}) está a caminho!",
        );
    }

    public function content(): Content
    {
        $correiosUrl = $this->order->carrier_tracking_code 
            ? "https://rastreamento.correios.com.br/app/index.php?codigo={$this->order->carrier_tracking_code}"
            : null;

        return new Content(
            view: 'emails.orders.shipped',
            with: [
                'order' => $this->order,
                'trackingUrl' => rtrim(config('app.url', 'http://localhost:5173'), '/') . "/?token={$this->order->tracking_token}",
                'correiosUrl' => $correiosUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
