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

class OrderConfirmedMail extends Mailable implements ShouldQueue
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
            subject: "🎉 Oba! Sua encomenda #{$this->order->order_number} foi confirmada! - MIMOO LAB",
        );
    }

    public function content(): Content
    {
        // Valores do snapshot gravado no pedido (centavos inteiros); nada é recalculado a partir de Product.
        $subtotalCents = (int) $this->order->items->sum(fn ($item): int => $item->unit_price_cents * $item->quantity);
        $shippingCents = (int) $this->order->shipping_cost_cents;
        $totalCents = (int) $this->order->total_cents;
        // total = peças − desconto PIX + frete (OrderCreationService)
        $discountCents = max(0, $subtotalCents + $shippingCents - $totalCents);

        return new Content(
            view: 'emails.orders.confirmed',
            with: [
                'order' => $this->order,
                'trackingUrl' => rtrim((string) config('app.frontend_url'), '/')."/?token={$this->order->tracking_token}",
                'totalAmountFormatted' => $this->formatCents($totalCents),
                'subtotalFormatted' => $this->formatCents($subtotalCents),
                'discountFormatted' => $discountCents > 0 ? '− '.$this->formatCents($discountCents) : null,
                'shippingFormatted' => $this->formatCents($shippingCents),
            ],
        );
    }

    private function formatCents(int $cents): string
    {
        return 'R$ '.number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
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
