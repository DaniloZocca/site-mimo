<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Mail\CustomerVerifyEmailMail;
use App\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critério 6: confirmação do e-mail pelo link assinado.
 */
class CustomerEmailVerificationTest extends CharacterizationTestCase
{
    private const FRONT = 'https://loja.exemplo.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => self::FRONT]);
    }

    public function test_link_do_email_confirma_e_volta_para_a_loja(): void
    {
        $customer = Customer::factory()->unverified()->create(['name' => 'Ana Clara', 'email' => 'ana@exemplo.com']);

        $customer->sendEmailVerificationNotification();

        $url = null;
        Mail::assertQueued(CustomerVerifyEmailMail::class, function (CustomerVerifyEmailMail $mail) use (&$url): bool {
            $mail->assertSeeInHtml('Confirmar meu e-mail');
            $mail->assertSeeInHtml('Olá, Ana!');
            $url = $mail->content()->with['verifyUrl'];

            return true;
        });

        $this->get($url)->assertRedirect(self::FRONT.'/?conta=verificada');
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
    }

    public function test_link_adulterado_nao_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $url = $this->signedUrl($customer);

        $this->get($url.'x')->assertRedirect(self::FRONT.'/?conta=link-invalido');
        $this->get(str_replace(sha1($customer->email), sha1('outro@exemplo.com'), $url))
            ->assertRedirect(self::FRONT.'/?conta=link-invalido');

        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_link_com_hash_de_outro_email_mesmo_assinado_nao_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('conta.verification.verify', now()->addHour(), [
            'id' => $customer->id,
            'hash' => sha1('outro@exemplo.com'),
        ]);

        $this->get($url)->assertRedirect(self::FRONT.'/?conta=link-invalido');
        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_link_vencido_nao_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $url = $this->signedUrl($customer);

        $this->travel(61)->minutes();

        $this->get($url)->assertRedirect(self::FRONT.'/?conta=link-invalido');
        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_reenviar_confirmacao_exige_login_e_respeita_o_limite(): void
    {
        $this->postJson('/api/conta/email/verification-notification')->assertStatus(401);

        $customer = Customer::factory()->unverified()->create();
        $this->actingAs($customer, 'customer');

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/conta/email/verification-notification')->assertStatus(202);
        }
        $this->postJson('/api/conta/email/verification-notification')->assertStatus(429);

        Mail::assertQueued(CustomerVerifyEmailMail::class, 3);
    }

    public function test_reenviar_com_email_ja_confirmado_nao_manda_nada(): void
    {
        $this->actingAs(Customer::factory()->create(), 'customer')
            ->postJson('/api/conta/email/verification-notification')
            ->assertOk();

        Mail::assertNothingQueued();
    }

    private function signedUrl(Customer $customer): string
    {
        return URL::temporarySignedRoute('conta.verification.verify', now()->addMinutes(60), [
            'id' => $customer->id,
            'hash' => sha1($customer->email),
        ]);
    }
}
