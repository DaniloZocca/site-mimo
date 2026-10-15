<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Mail\CustomerResetPasswordMail;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critério 8: "Esqueci minha senha" e troca pelo link do e-mail.
 */
class CustomerPasswordResetTest extends CharacterizationTestCase
{
    private const INVALID_LINK = 'Este link de nova senha não vale mais. Peça outro.';

    public function test_email_com_e_sem_conta_recebem_a_mesma_resposta_e_so_o_existente_recebe_email(): void
    {
        config(['app.frontend_url' => 'https://loja.exemplo.test']);
        Customer::factory()->create(['name' => 'Ana Clara', 'email' => 'ana@exemplo.com']);

        $existing = $this->postJson('/api/conta/forgot-password', ['email' => 'ANA@exemplo.com']);
        $missing = $this->postJson('/api/conta/forgot-password', ['email' => 'ninguem@exemplo.com']);

        $existing->assertStatus(202);
        $missing->assertStatus(202);
        $this->assertSame($existing->json(), $missing->json());

        Mail::assertQueued(CustomerResetPasswordMail::class, 1);
        Mail::assertQueued(CustomerResetPasswordMail::class, function (CustomerResetPasswordMail $mail): bool {
            $url = $mail->content()->with['resetUrl'];
            $mail->assertSeeInHtml('Criar nova senha');

            return $mail->hasTo('ana@exemplo.com')
                && $url === 'https://loja.exemplo.test/?reset='.urlencode($mail->token)
                && ! str_contains($url, 'ana@exemplo.com');
        });
    }

    public function test_segundo_pedido_logo_em_seguida_tem_a_mesma_resposta(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.com']);

        $first = $this->postJson('/api/conta/forgot-password', ['email' => 'ana@exemplo.com'])->assertStatus(202);
        // O broker segura um novo e-mail por 60 s; a resposta não pode mudar e revelar a conta.
        $second = $this->postJson('/api/conta/forgot-password', ['email' => 'ana@exemplo.com'])->assertStatus(202);

        $this->assertSame($first->json(), $second->json());
        Mail::assertQueued(CustomerResetPasswordMail::class, 1);
    }

    public function test_token_valido_troca_a_senha_e_outras_sessoes_caem(): void
    {
        $customer = Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'antiga123']);
        $oldRemember = $customer->remember_token;
        $oldSessionHash = Auth::guard('customer')->hashPasswordForCookie($customer->getAuthPassword());
        $token = $this->requestToken();

        $this->postJson('/api/conta/reset-password', [
            'token' => $token,
            'email' => 'Ana@Exemplo.com',
            'password' => 'novinha123',
            'password_confirmation' => 'novinha123',
        ])->assertOk();

        $customer->refresh();
        $this->assertTrue(Hash::check('novinha123', $customer->getAuthPassword()));
        $this->assertNotSame($oldRemember, $customer->remember_token);

        // Uma sessão aberta antes da troca (hash da senha antiga guardado) não vale mais.
        $this->actingAs($customer, 'customer')
            ->withSession(['password_hash_customer' => $oldSessionHash])
            ->getJson('/api/conta/me')
            ->assertStatus(401);

        // A senha nova entra.
        Auth::guard('customer')->logout();
        $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'novinha123'])->assertOk();
    }

    public function test_token_ja_usado_da_422(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.com']);
        $token = $this->requestToken();
        $payload = ['token' => $token, 'email' => 'ana@exemplo.com', 'password' => 'novinha123', 'password_confirmation' => 'novinha123'];

        $this->postJson('/api/conta/reset-password', $payload)->assertOk();
        $this->postJson('/api/conta/reset-password', ['password' => 'outra1234', 'password_confirmation' => 'outra1234'] + $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', self::INVALID_LINK);
    }

    public function test_token_vencido_ou_de_outro_email_da_422_e_nao_troca(): void
    {
        $customer = Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'antiga123']);
        Customer::factory()->create(['email' => 'bia@exemplo.com']);
        $token = $this->requestToken();

        $this->postJson('/api/conta/reset-password', [
            'token' => $token, 'email' => 'bia@exemplo.com', 'password' => 'novinha123', 'password_confirmation' => 'novinha123',
        ])->assertStatus(422)->assertJsonPath('errors.token.0', self::INVALID_LINK);

        $this->travel(61)->minutes();

        $this->postJson('/api/conta/reset-password', [
            'token' => $token, 'email' => 'ana@exemplo.com', 'password' => 'novinha123', 'password_confirmation' => 'novinha123',
        ])->assertStatus(422)->assertJsonPath('errors.token.0', self::INVALID_LINK);

        $this->assertTrue(Hash::check('antiga123', $customer->fresh()->getAuthPassword()));
    }

    public function test_email_sem_conta_tem_a_mesma_resposta_de_token_invalido(): void
    {
        $this->postJson('/api/conta/reset-password', [
            'token' => 'qualquer', 'email' => 'ninguem@exemplo.com', 'password' => 'novinha123', 'password_confirmation' => 'novinha123',
        ])->assertStatus(422)->assertJsonPath('errors.token.0', self::INVALID_LINK);
    }

    public function test_quarto_pedido_de_link_no_mesmo_minuto_da_429(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/conta/forgot-password', ['email' => 'ana@exemplo.com'])->assertStatus(202);
        }

        $this->postJson('/api/conta/forgot-password', ['email' => 'ana@exemplo.com'])->assertStatus(429);
    }

    private function requestToken(): string
    {
        $this->postJson('/api/conta/forgot-password', ['email' => 'ana@exemplo.com'])->assertStatus(202);

        $token = null;
        Mail::assertQueued(CustomerResetPasswordMail::class, function (CustomerResetPasswordMail $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        return (string) $token;
    }
}
