<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Mail\CustomerResetPasswordMail;
use App\Mail\CustomerVerifyEmailMail;
use App\Models\Customer;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critério 9: nenhum log dos fluxos de conta leva e-mail, senha ou token (regra 8 do CLAUDE.md),
 * nem quando o envio do e-mail falha com uma exceção que carrega o endereço.
 */
class CustomerAccountLogPrivacyTest extends CharacterizationTestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    public function test_fluxos_de_conta_nao_logam_dados_sensiveis(): void
    {
        $this->postJson('/api/conta/register', [
            'name' => 'Ana Clara', 'email' => 'ana@exemplo.com', 'password' => 'gatinho123', 'password_confirmation' => 'gatinho123',
        ])->assertCreated();
        $this->postJson('/api/conta/logout')->assertNoContent();
        $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'errada123'])->assertStatus(422);
        $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'gatinho123'])->assertOk();
        $this->postJson('/api/conta/forgot-password', ['email' => 'ana@exemplo.com'])->assertStatus(202);

        $token = null;
        Mail::assertQueued(CustomerResetPasswordMail::class, function (CustomerResetPasswordMail $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });
        $this->postJson('/api/conta/reset-password', [
            'token' => $token, 'email' => 'ana@exemplo.com', 'password' => 'novinha123', 'password_confirmation' => 'novinha123',
        ])->assertOk();

        $this->assertNothingSensitiveLogged(['ana@exemplo.com', 'gatinho123', 'errada123', 'novinha123', (string) $token]);
    }

    public function test_falha_ao_enfileirar_email_nao_derruba_o_cadastro_e_loga_so_as_classes(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP recusou bia@exemplo.com'));

        $this->postJson('/api/conta/register', [
            'name' => 'Bia', 'email' => 'bia@exemplo.com', 'password' => 'gatinho123', 'password_confirmation' => 'gatinho123',
        ])->assertCreated();

        $this->assertSame(1, Customer::count());
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('Falha ao enfileirar e-mail de conta de cliente.', $this->logged[0]);
        $this->assertStringContainsString(json_encode(CustomerVerifyEmailMail::class), $this->logged[0]);
        $this->assertStringContainsString(json_encode(RuntimeException::class), $this->logged[0]);
        $this->assertNothingSensitiveLogged(['bia@exemplo.com', 'gatinho123', 'SMTP recusou']);
    }

    /**
     * @param  list<string>  $secrets
     */
    private function assertNothingSensitiveLogged(array $secrets): void
    {
        foreach ($this->logged as $line) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $line);
                $this->assertStringNotContainsString(str_replace('/', '\/', $secret), $line);
            }
        }
    }
}
