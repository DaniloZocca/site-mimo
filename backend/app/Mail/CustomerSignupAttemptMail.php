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
 * M-44: aviso para a dona da conta quando alguém tenta se cadastrar com o e-mail dela. O cadastro responde
 * igual nos dois casos, então este e-mail é o único lugar que diz "já existe conta". Não leva nada do que
 * foi digitado no cadastro (nome, senha) nem dados de quem tentou.
 */
class CustomerSignupAttemptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🐮 Alguém tentou criar uma conta com seu e-mail',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conta.signup-attempt',
            with: [
                'firstName' => explode(' ', trim($this->customer->name ?: 'Cliente'))[0],
                'storeUrl' => rtrim((string) config('app.frontend_url'), '/').'/',
            ],
        );
    }
}
