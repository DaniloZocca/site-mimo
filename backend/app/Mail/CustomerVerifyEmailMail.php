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
 * M-26: "Confirme seu e-mail". O link abre a loja (FRONTEND_URL/?verificar=1&id=…&hash=…&expires=…&signature=…);
 * a loja pede para entrar, se preciso, e manda POST /api/conta/email/verify/{id}/{hash}?expires=…&signature=…
 * com a sessão. A confirmação só vale logada na própria conta: quem criou a conta com o e-mail de outra
 * pessoa não recebe o link, e quem recebe o link não está logado na conta do outro.
 * Assinatura de 60 minutos, gerada para a rota da API (APP_URL).
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
        $signed = URL::temporarySignedRoute(
            'conta.verification.verify',
            now()->addMinutes(self::LINK_MINUTES),
            [
                'id' => $this->customer->getKey(),
                'hash' => sha1($this->customer->getEmailForVerification()),
            ]
        );
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $signature);

        $query = http_build_query([
            'verificar' => 1,
            'id' => $this->customer->getKey(),
            'hash' => sha1($this->customer->getEmailForVerification()),
            'expires' => $signature['expires'] ?? '',
            'signature' => $signature['signature'] ?? '',
        ]);

        return new Content(
            view: 'emails.conta.verify',
            with: [
                'firstName' => explode(' ', trim($this->customer->name ?: 'Cliente'))[0],
                'verifyUrl' => rtrim((string) config('app.frontend_url'), '/').'/?'.$query,
                'linkMinutes' => self::LINK_MINUTES,
            ],
        );
    }
}
