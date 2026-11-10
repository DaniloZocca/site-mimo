<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-50 (d): o remetente pedidos@ não tem caixa de entrada. Com MAIL_REPLY_TO_ADDRESS, todo e-mail da loja leva
 * Reply-To para o contato da loja; sem ela, nenhum Reply-To (como antes).
 */
class MailReplyToTest extends CharacterizationTestCase
{
    public function test_com_endereco_configurado_todo_email_leva_reply_to(): void
    {
        config()->set('mail.reply_to', ['address' => 'contato@loja.exemplo.test', 'name' => 'MIMOO LAB']);

        $email = $this->sendAndCapture();

        $this->assertCount(1, $email->getReplyTo());
        $this->assertSame('contato@loja.exemplo.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('MIMOO LAB', $email->getReplyTo()[0]->getName());
    }

    public function test_sem_endereco_nao_ha_reply_to(): void
    {
        config()->set('mail.reply_to', ['address' => null, 'name' => 'MIMOO LAB']);

        $this->assertSame([], $this->sendAndCapture()->getReplyTo());
    }

    public function test_config_le_o_env_e_trata_vazio_como_sem_reply_to(): void
    {
        $original = [$_ENV['MAIL_REPLY_TO_ADDRESS'] ?? null, $_SERVER['MAIL_REPLY_TO_ADDRESS'] ?? null, getenv('MAIL_REPLY_TO_ADDRESS')];

        try {
            $this->setEnv('');
            $this->assertNull((require config_path('mail.php'))['reply_to']['address']);

            $this->setEnv('contato@loja.exemplo.test');
            $this->assertSame('contato@loja.exemplo.test', (require config_path('mail.php'))['reply_to']['address']);
        } finally {
            $this->setEnv(null);
            [$env, $server, $putenv] = $original;
            if ($env !== null) {
                $_ENV['MAIL_REPLY_TO_ADDRESS'] = $env;
            }
            if ($server !== null) {
                $_SERVER['MAIL_REPLY_TO_ADDRESS'] = $server;
            }
            if ($putenv !== false) {
                putenv("MAIL_REPLY_TO_ADDRESS={$putenv}");
            }
        }
    }

    private function sendAndCapture(): Email
    {
        // A base dos testes troca o Mail por um falso (que não aplica o Reply-To global): aqui o gerenciador é o real
        $manager = new MailManager($this->app);
        $mailer = $manager->mailer('array');

        $mailer->raw('Olá', fn (Message $message) => $message->to('cliente@exemplo.test')->subject('Teste'));

        $messages = $mailer->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['MAIL_REPLY_TO_ADDRESS'], $_SERVER['MAIL_REPLY_TO_ADDRESS']);
            putenv('MAIL_REPLY_TO_ADDRESS');

            return;
        }

        $_ENV['MAIL_REPLY_TO_ADDRESS'] = $_SERVER['MAIL_REPLY_TO_ADDRESS'] = $value;
        putenv("MAIL_REPLY_TO_ADDRESS={$value}");
    }
}
