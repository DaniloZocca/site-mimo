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

/**
 * M-26: "Crie uma nova senha". O link abre a loja (FRONTEND_URL/?reset=<token>); o e-mail não vai na URL,
 * a pessoa digita de novo na tela "Nova senha". O token vale o `expire` do broker `customers` (60 min).
 */
class CustomerResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $token
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🔑 Crie uma nova senha na MIMOO LAB',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conta.reset-password',
            with: [
                'firstName' => explode(' ', trim($this->customer->name ?: 'Cliente'))[0],
                'resetUrl' => rtrim((string) config('app.frontend_url'), '/').'/?reset='.urlencode($this->token),
                'linkMinutes' => (int) config('auth.passwords.customers.expire', 60),
            ],
        );
    }
}
