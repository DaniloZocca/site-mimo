<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPrintingStartedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order
    ) {
        $this->order->loadMissing('items');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🖨️ As máquinas estão trabalhando na sua encomenda #{$this->order->order_number}! - MIMOO LAB",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.printing',
            with: [
                'order' => $this->order,
                'trackingUrl' => rtrim((string) config('app.frontend_url'), '/')."/?token={$this->order->tracking_token}",
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
