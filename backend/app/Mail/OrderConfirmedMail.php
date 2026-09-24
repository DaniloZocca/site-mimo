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

class OrderConfirmedMail extends Mailable implements ShouldQueue
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
            subject: "🎉 Oba! Sua encomenda #{$this->order->order_number} foi confirmada! - MIMOO LAB",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.confirmed',
            with: [
                'order' => $this->order,
                'trackingUrl' => rtrim(config('app.url', 'http://localhost:5173'), '/') . "/?token={$this->order->tracking_token}",
                'totalAmountFormatted' => 'R$ ' . number_format($this->order->total_amount_cents / 100, 2, ',', '.'),
                'subtotalFormatted' => 'R$ ' . number_format($this->order->subtotal_cents / 100, 2, ',', '.'),
                'shippingFormatted' => $this->order->shipping_cost_cents > 0 
                    ? 'R$ ' . number_format($this->order->shipping_cost_cents / 100, 2, ',', '.') 
                    : 'GRÁTIS',
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
