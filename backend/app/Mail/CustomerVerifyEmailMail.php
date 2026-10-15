<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * M-26: "Confirme seu e-mail". O link é assinado, vale 60 minutos e aponta para a API,
 * que confirma e devolve o cliente para a loja (FRONTEND_URL/?conta=verificada).
 */
class CustomerVerifyEmailMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const LINK_MINUTES = 60;

    public function __construct(
        public Customer $customer
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🐮 Confirme seu e-mail na MIMOO LAB',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conta.verify',
            with: [
                'firstName' => explode(' ', trim($this->customer->name ?: 'Cliente'))[0],
                'verifyUrl' => URL::temporarySignedRoute(
                    'conta.verification.verify',
                    now()->addMinutes(self::LINK_MINUTES),
                    [
                        'id' => $this->customer->getKey(),
                        'hash' => sha1($this->customer->getEmailForVerification()),
                    ]
                ),
                'linkMinutes' => self::LINK_MINUTES,
            ],
        );
    }
}
