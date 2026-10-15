<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Mail\CustomerVerifyEmailMail;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critério 6: confirmação do e-mail. O link do e-mail abre a loja; a loja chama a API logada.
 * A confirmação só vale na sessão da própria conta (achado da revisão de segurança: sem isso, quem
 * criasse a conta com o e-mail de outra pessoa via os pedidos dela assim que ela clicasse no link).
 */
class CustomerEmailVerificationTest extends CharacterizationTestCase
{
    private const FRONT = 'https://loja.exemplo.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => self::FRONT]);
    }

    public function test_link_do_email_abre_a_loja_e_a_loja_logada_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create(['name' => 'Ana Clara', 'email' => 'ana@exemplo.com']);

        $customer->sendEmailVerificationNotification();

        $url = $this->mailedUrl();
        $this->assertStringStartsWith(self::FRONT.'/?verificar=1&id='.$customer->id.'&hash='.sha1('ana@exemplo.com').'&expires=', $url);
        $this->assertStringNotContainsString('ana@exemplo.com', $url);

        $this->actingAs($customer, 'customer')
            ->postJson($this->apiUrlFromStoreLink($url))
            ->assertOk()
            ->assertJsonPath('customer.email_verified', true);

        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
    }

    public function test_sem_login_o_link_nao_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create();

        $this->postJson($this->signedApiUrl($customer))->assertStatus(401);

        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_conta_criada_com_email_de_outra_pessoa_nao_e_confirmada_pelo_clique_dela(): void
    {
        // Atacante cria a conta com o e-mail da vítima; a vítima recebe o link e clica logada na conta dela.
        $hijacked = Customer::factory()->unverified()->create(['email' => 'vitima@exemplo.com', 'password' => 'doAtacante1']);
        Order::factory()->create(['customer_email' => 'vitima@exemplo.com']);
        $victimOtherAccount = Customer::factory()->create(['email' => 'vitima.outra@exemplo.com']);

        $this->actingAs($victimOtherAccount, 'customer')
            ->postJson($this->signedApiUrl($hijacked))
            ->assertStatus(403)
            ->assertJsonPath('code', 'verification_other_account');

        $this->assertFalse($hijacked->fresh()->hasVerifiedEmail());
        // A sessão do teste guarda o hash da senha da outra conta (auth.session): começa limpa para o atacante.
        $this->flushSession();
        $this->actingAs($hijacked->fresh(), 'customer')->getJson('/api/conta/orders')->assertStatus(403);
    }

    public function test_link_adulterado_ou_com_hash_de_outro_email_nao_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $this->actingAs($customer, 'customer');

        $this->postJson($this->signedApiUrl($customer).'x')->assertStatus(403)->assertJsonPath('code', 'verification_link_invalid');

        $otherHash = URL::temporarySignedRoute('conta.verification.verify', now()->addHour(), [
            'id' => $customer->id,
            'hash' => sha1('outro@exemplo.com'),
        ]);
        $this->postJson($otherHash)->assertStatus(403)->assertJsonPath('code', 'verification_link_invalid');

        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_link_vencido_nao_confirma(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $url = $this->signedApiUrl($customer);

        $this->travel(61)->minutes();

        $this->actingAs($customer, 'customer')->postJson($url)->assertStatus(403)->assertJsonPath('code', 'verification_link_invalid');
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

    private function mailedUrl(): string
    {
        $url = null;
        Mail::assertQueued(CustomerVerifyEmailMail::class, function (CustomerVerifyEmailMail $mail) use (&$url): bool {
            $mail->assertSeeInHtml('Confirmar meu e-mail');
            $mail->assertSeeInHtml('Olá, Ana!');
            $url = $mail->content()->with['verifyUrl'];

            return true;
        });

        return (string) $url;
    }

    /**
     * O que a loja faz com o link: POST /api/conta/email/verify/{id}/{hash}?expires=…&signature=…
     */
    private function apiUrlFromStoreLink(string $storeUrl): string
    {
        parse_str((string) parse_url($storeUrl, PHP_URL_QUERY), $q);

        return "/api/conta/email/verify/{$q['id']}/{$q['hash']}?".http_build_query(['expires' => $q['expires'], 'signature' => $q['signature']]);
    }

    private function signedApiUrl(Customer $customer): string
    {
        return URL::temporarySignedRoute('conta.verification.verify', now()->addMinutes(60), [
            'id' => $customer->id,
            'hash' => sha1($customer->email),
        ]);
    }
}
