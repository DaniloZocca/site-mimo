<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Http\Requests\Conta\RegisterCustomerRequest;
use App\Mail\CustomerVerifyEmailMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critérios 1 e bloqueio do domínio da loja: cadastro de conta de cliente.
 */
class CustomerRegistrationTest extends CharacterizationTestCase
{
    public function test_cadastro_valido_cria_conta_com_email_minusculo_senha_com_hash_loga_e_enfileira_confirmacao(): void
    {
        $response = $this->postJson('/api/conta/register', [
            'name' => '  Ana Clara Silva ',
            'email' => '  Ana.Clara@Exemplo.COM ',
            'password' => 'gatinho123',
            'password_confirmation' => 'gatinho123',
        ]);

        $response->assertCreated()->assertExactJson([
            'customer' => ['name' => 'Ana Clara Silva', 'email' => 'ana.clara@exemplo.com', 'email_verified' => false],
        ]);

        $customer = Customer::sole();
        $this->assertSame('ana.clara@exemplo.com', $customer->email);
        $this->assertNotSame('gatinho123', $customer->getAuthPassword());
        $this->assertTrue(Hash::check('gatinho123', $customer->getAuthPassword()));
        $this->assertNull($customer->email_verified_at);
        $this->assertAuthenticatedAs($customer, 'customer');

        Mail::assertQueued(CustomerVerifyEmailMail::class, fn (CustomerVerifyEmailMail $mail): bool => $mail->hasTo('ana.clara@exemplo.com') && $mail->customer->is($customer));
    }

    public function test_email_repetido_em_qualquer_caixa_da_422_e_nao_cria_outra_conta(): void
    {
        Customer::factory()->create(['email' => 'ana@exemplo.com']);

        $this->postJson('/api/conta/register', $this->payload(['email' => 'ANA@Exemplo.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', RegisterCustomerRequest::EMAIL_TAKEN_MESSAGE);

        $this->assertSame(1, Customer::count());
        Mail::assertNothingQueued();
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
            ->assertCreated();
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
        $this->postJson('/api/conta/register', $this->payload())->assertCreated();

        $this->assertSame(0, User::count());
    }

    public function test_quarto_cadastro_no_mesmo_minuto_do_mesmo_ip_da_429(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/conta/register', $this->payload(['email' => "pessoa{$i}@exemplo.com"]))->assertCreated();
            $this->postJson('/api/conta/logout');
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
