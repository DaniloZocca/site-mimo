<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Http\Controllers\Api\Conta\AccountController;
use App\Http\Requests\Conta\RegisterCustomerRequest;
use App\Mail\CustomerSignupAttemptMail;
use App\Mail\CustomerVerifyEmailMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critérios 1 e bloqueio do domínio da loja: cadastro de conta de cliente.
 */
class CustomerRegistrationTest extends CharacterizationTestCase
{
    public function test_cadastro_valido_cria_conta_com_email_minusculo_senha_com_hash_nao_loga_e_enfileira_confirmacao(): void
    {
        $response = $this->postJson('/api/conta/register', [
            'name' => '  Ana Clara Silva ',
            'email' => '  Ana.Clara@Exemplo.COM ',
            'password' => 'gatinho123',
            'password_confirmation' => 'gatinho123',
        ]);

        // M-44: resposta neutra (a mesma do e-mail que já tem conta), sem dados da conta e sem login.
        $response->assertStatus(202)->assertExactJson(['message' => AccountController::REGISTERED_MESSAGE]);

        $customer = Customer::sole();
        $this->assertSame('ana.clara@exemplo.com', $customer->email);
        $this->assertNotSame('gatinho123', $customer->getAuthPassword());
        $this->assertTrue(Hash::check('gatinho123', $customer->getAuthPassword()));
        $this->assertNull($customer->email_verified_at);
        $this->assertGuest('customer');

        Mail::assertNotQueued(CustomerSignupAttemptMail::class);
        Mail::assertQueued(CustomerVerifyEmailMail::class, fn (CustomerVerifyEmailMail $mail): bool => $mail->hasTo('ana.clara@exemplo.com') && $mail->customer->is($customer));
    }

    public function test_email_que_ja_tem_conta_responde_igual_ao_novo_nao_mexe_na_conta_e_avisa_a_dona(): void
    {
        $novo = $this->postJson('/api/conta/register', $this->payload(['email' => 'bia@exemplo.com']));
        Mail::fake();

        $existente = Customer::factory()->create([
            'name' => 'Ana Original', 'email' => 'ana@exemplo.com', 'password' => 'senhaantiga1',
        ]);
        $hashAntes = $existente->getAuthPassword();

        $repetido = $this->postJson('/api/conta/register', $this->payload([
            'name' => 'Outra Pessoa', 'email' => '  ANA@Exemplo.com ', 'password' => 'invasor123', 'password_confirmation' => 'invasor123',
        ]));

        $this->assertSame($novo->status(), $repetido->status());
        $this->assertSame($novo->getContent(), $repetido->getContent());
        $repetido->assertStatus(202)->assertExactJson(['message' => AccountController::REGISTERED_MESSAGE]);

        $this->assertSame(1, Customer::where('email', 'ana@exemplo.com')->count());
        $existente->refresh();
        $this->assertSame('Ana Original', $existente->name);
        $this->assertSame($hashAntes, $existente->getAuthPassword());
        $this->assertGuest('customer');

        Mail::assertNotQueued(CustomerVerifyEmailMail::class);
        Mail::assertQueued(CustomerSignupAttemptMail::class, 1);
        Mail::assertQueued(CustomerSignupAttemptMail::class, function (CustomerSignupAttemptMail $mail) use ($existente): bool {
            $html = $mail->render();

            return $mail->hasTo('ana@exemplo.com')
                && $mail->customer->is($existente)
                && str_contains($html, 'Oi, Ana!')
                && ! str_contains($html, 'invasor123')
                && ! str_contains($html, 'Outra Pessoa');
        });
    }

    public function test_aviso_de_tentativa_sai_no_maximo_uma_vez_por_hora_por_conta(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.com']);

        $this->postJson('/api/conta/register', $this->payload())->assertStatus(202);
        $this->postJson('/api/conta/register', $this->payload())
            ->assertStatus(202)
            ->assertExactJson(['message' => AccountController::REGISTERED_MESSAGE]);
        Mail::assertQueued(CustomerSignupAttemptMail::class, 1);

        $this->travel(Customer::SIGNUP_ATTEMPT_WARNING_MINUTES + 1)->minutes();
        RateLimiter::clear('register:127.0.0.1');

        $this->postJson('/api/conta/register', $this->payload())->assertStatus(202);
        Mail::assertQueued(CustomerSignupAttemptMail::class, 2);
        $this->assertSame(1, Customer::count());
    }

    public function test_cadastro_simultaneo_pego_pelo_unique_responde_igual_e_avisa(): void
    {
        // Simula a corrida: a conta aparece no banco entre a consulta do controller e o INSERT.
        Customer::creating(function (Customer $customer): void {
            if (Customer::where('email', $customer->email)->doesntExist()) {
                Customer::withoutEvents(fn () => Customer::factory()->create(['email' => $customer->email]));
            }
        });

        $this->postJson('/api/conta/register', $this->payload())
            ->assertStatus(202)
            ->assertExactJson(['message' => AccountController::REGISTERED_MESSAGE]);

        $this->assertSame(1, Customer::count());
        $this->assertGuest('customer');
        Mail::assertNotQueued(CustomerVerifyEmailMail::class);
        Mail::assertQueued(CustomerSignupAttemptMail::class, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emailsDaLoja(): array
    {
        return [
            'domínio da loja' => ['fernanda@mimoolab.com.br'],
            'maiúsculas' => ['Contato@MimooLab.com.BR'],
            'subdomínio' => ['teste@loja.mimoolab.com.br'],
        ];
    }

    #[DataProvider('emailsDaLoja')]
    public function test_email_do_dominio_da_loja_da_422_com_mensagem_neutra(string $email): void
    {
        $this->postJson('/api/conta/register', $this->payload(['email' => $email]))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', RegisterCustomerRequest::BLOCKED_DOMAIN_MESSAGE)
            ->assertJsonPath('errors.email', [RegisterCustomerRequest::BLOCKED_DOMAIN_MESSAGE]);

        $this->assertSame(0, Customer::count());
        Mail::assertNothingQueued();
    }

    public function test_dominio_parecido_com_o_da_loja_pode_se_cadastrar(): void
    {
        $this->postJson('/api/conta/register', $this->payload(['email' => 'ana@naomimoolab.com.br']))
            ->assertStatus(202);

        $this->assertSame(1, Customer::count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function senhasFracas(): array
    {
        return [
            'curta' => ['abc123', 'abc123'],
            'só letras' => ['abcdefghi', 'abcdefghi'],
            'só números' => ['12345678', '12345678'],
            'maior que 72' => [str_repeat('a1', 37), str_repeat('a1', 37)],
            'confirmação diferente' => ['gatinho123', 'gatinho124'],
        ];
    }

    #[DataProvider('senhasFracas')]
    public function test_senha_fora_da_regra_da_422_em_portugues(string $password, string $confirmation): void
    {
        $response = $this->postJson('/api/conta/register', $this->payload([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertContains($response->json('errors.password.0'), [
            RegisterCustomerRequest::PASSWORD_MESSAGE,
            'A confirmação não é igual à senha.',
        ]);
        $this->assertSame(0, Customer::count());
    }

    public function test_cadastro_nao_mexe_na_tabela_de_administradores(): void
    {
        $this->postJson('/api/conta/register', $this->payload())->assertStatus(202);

        $this->assertSame(0, User::count());
    }

    public function test_quarto_cadastro_no_mesmo_minuto_do_mesmo_ip_da_429(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/conta/register', $this->payload(['email' => "pessoa{$i}@exemplo.com"]))->assertStatus(202);
        }

        $this->postJson('/api/conta/register', $this->payload(['email' => 'pessoa4@exemplo.com']))->assertStatus(429);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ana Clara Silva',
            'email' => 'ana@exemplo.com',
            'password' => 'gatinho123',
            'password_confirmation' => 'gatinho123',
        ];
    }
}
