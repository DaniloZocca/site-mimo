<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critérios 2 a 5: login, limite de tentativas, logout, CSRF e separação do painel.
 */
class CustomerSessionTest extends CharacterizationTestCase
{
    private const INVALID = 'E-mail ou senha não conferem.';

    public function test_login_certo_devolve_o_cliente_e_me_responde_com_a_sessao(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ana Clara', 'email' => 'ana@exemplo.com', 'password' => 'gatinho123']);

        $this->postJson('/api/conta/login', ['email' => ' ANA@exemplo.com ', 'password' => 'gatinho123'])
            ->assertOk()
            ->assertExactJson(['customer' => ['name' => 'Ana Clara', 'email' => 'ana@exemplo.com', 'email_verified' => true]]);

        $this->assertAuthenticatedAs($customer, 'customer');

        $this->getJson('/api/conta/me')
            ->assertOk()
            ->assertJsonPath('customer.email', 'ana@exemplo.com');
    }

    public function test_senha_errada_e_email_inexistente_dao_a_mesma_422(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'gatinho123']);

        $wrongPassword = $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'outra123']);
        $unknownEmail = $this->postJson('/api/conta/login', ['email' => 'ninguem@exemplo.com', 'password' => 'outra123']);

        $wrongPassword->assertStatus(422)->assertJsonPath('errors.email.0', self::INVALID);
        $unknownEmail->assertStatus(422)->assertJsonPath('errors.email.0', self::INVALID);
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertGuest('customer');
    }

    public function test_sexto_login_no_mesmo_minuto_do_mesmo_ip_e_email_da_429(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'gatinho123']);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'errada123'])->assertStatus(422);
        }

        // Mesmo com a senha certa: o limite vale antes de conferir a senha.
        $this->postJson('/api/conta/login', ['email' => 'ANA@exemplo.com', 'password' => 'gatinho123'])->assertStatus(429);
        $this->assertGuest('customer');
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $customer = Customer::factory()->create(['password' => 'gatinho123']);
        $this->postJson('/api/conta/login', ['email' => $customer->email, 'password' => 'gatinho123'])->assertOk();

        $this->postJson('/api/conta/logout')->assertNoContent();

        $this->assertGuest('customer');
        $this->getJson('/api/conta/me')->assertStatus(401);
    }

    public function test_me_sem_sessao_da_401_em_json_mesmo_sem_pedir_json(): void
    {
        $this->getJson('/api/conta/me')->assertStatus(401);
        $this->get('/api/conta/me')->assertStatus(401)->assertJsonStructure(['message']);
        $this->get('/api/conta/orders')->assertStatus(401);
    }

    public function test_requisicao_sem_token_csrf_da_419(): void
    {
        $this->enforceCsrf();
        Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'gatinho123']);

        $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'gatinho123'])->assertStatus(419);
        $this->postJson('/api/conta/register', [
            'name' => 'Ana', 'email' => 'nova@exemplo.com', 'password' => 'gatinho123', 'password_confirmation' => 'gatinho123',
        ])->assertStatus(419);
        $this->assertGuest('customer');
        $this->assertSame(1, Customer::count());
    }

    public function test_com_o_cookie_xsrf_o_login_passa_pelo_csrf(): void
    {
        $this->enforceCsrf();
        Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'gatinho123']);

        $token = 'token-csrf-de-teste';
        $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'gatinho123'])
            ->assertOk();
    }

    public function test_checkout_frete_e_rastreio_continuam_sem_csrf(): void
    {
        $this->enforceCsrf();

        // Sem sessão nem token: a validação responde (422), não o CSRF (419).
        $this->postJson('/api/orders/track', [])->assertStatus(422);
        $this->postJson('/api/shipping/calculate', [])->assertStatus(422);
    }

    public function test_administrador_nao_entra_pela_conta_de_cliente(): void
    {
        User::factory()->create(['email' => 'admin@exemplo.com', 'password' => 'senhaAdmin123', 'is_admin' => true]);

        $this->postJson('/api/conta/login', ['email' => 'admin@exemplo.com', 'password' => 'senhaAdmin123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', self::INVALID);
        $this->assertGuest('customer');
    }

    public function test_cliente_logado_nao_entra_no_painel(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->get('/admin')
            ->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn (Application $app) => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
