<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Tracking;

use App\Models\Order;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * POST|GET /api/orders/track — formas de localizar o pedido.
 * O parâmetro real do CPF é `cpf_prefix` (prefixo, não o CPF completo).
 */
#[Group('characterization')]
class TrackingLookupTest extends CharacterizationTestCase
{
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->order = Order::factory()->create([
            'order_number' => 'MIMOO-ABC12345',
            'customer_cpf' => '52998224725',
            'customer_email' => 'Fernanda@Example.com',
        ]);
    }

    private function track(array $data): TestResponse
    {
        return $this->postJson('/api/orders/track', $data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedCpfPrefixes(): array
    {
        return [
            'CPF completo sem máscara' => ['52998224725'],
            'CPF completo com máscara' => ['529.982.247-25'],
            'prefixo de 3 dígitos' => ['529'],
            'prefixo com máscara' => ['529.98'],
        ];
    }

    #[DataProvider('acceptedCpfPrefixes')]
    public function test_order_number_mais_prefixo_de_cpf(string $cpfPrefix): void
    {
        $this->track(['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => $cpfPrefix])
            ->assertOk()
            ->assertJsonPath('order_number', 'MIMOO-ABC12345');
    }

    public function test_prefixo_de_um_digito_e_aceito(): void
    {
        // BUG CONHECIDO: prefixo de CPF de 1 dígito basta para acessar o pedido — ver teste gêmeo em KnownBugs
        $this->track(['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '5'])
            ->assertOk()
            ->assertJsonPath('tracking_token', $this->order->tracking_token);
    }

    public function test_cpf_gravado_com_mascara_tambem_e_encontrado(): void
    {
        $this->order->update(['customer_cpf' => '529.982.247-25']);

        $this->track(['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '52998'])->assertOk();
    }

    public function test_cpf_prefix_so_com_pontuacao_vira_vazio_e_nao_encontra(): void
    {
        $this->track(['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '...-'])->assertNotFound();
    }

    public function test_order_number_e_normalizado_para_maiusculas_sem_espacos(): void
    {
        $this->track(['order_number' => '  mimoo-abc12345 ', 'cpf_prefix' => '529'])->assertOk();
    }

    public function test_email_confere_sem_diferenciar_maiusculas(): void
    {
        $this->track(['order_number' => 'MIMOO-ABC12345', 'email' => 'fernanda@example.COM'])->assertOk();
    }

    public function test_tracking_token_sozinho_localiza_o_pedido(): void
    {
        $this->track(['tracking_token' => $this->order->tracking_token])
            ->assertOk()
            ->assertJsonPath('order_number', 'MIMOO-ABC12345');
    }

    public function test_token_tem_prioridade_sobre_order_number(): void
    {
        $other = Order::factory()->create(['order_number' => 'MIMOO-OUTRO999']);

        $this->track(['tracking_token' => $other->tracking_token, 'order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '000'])
            ->assertOk()
            ->assertJsonPath('order_number', 'MIMOO-OUTRO999');
    }

    public function test_token_inexistente_retorna_404(): void
    {
        $this->track(['tracking_token' => (string) Str::uuid()])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Nenhuma encomenda localizada com a combinação de dados informada.']);
    }

    public function test_get_com_cpf_na_query_string_funciona(): void
    {
        // BUG CONHECIDO: rastreio aceita GET, expondo o prefixo do CPF na URL e nos logs — ver teste gêmeo em KnownBugs
        $this->getJson('/api/orders/track?order_number=MIMOO-ABC12345&cpf_prefix=52998224725')
            ->assertOk()
            ->assertJsonPath('order_number', 'MIMOO-ABC12345');
    }

    public function test_sem_identificador_retorna_422(): void
    {
        $this->track([])
            ->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'Informe o token de rastreio ou o número da encomenda.');
    }

    public function test_order_number_sem_fator_de_autenticacao_retorna_422(): void
    {
        $this->track(['order_number' => 'MIMOO-ABC12345'])
            ->assertStatus(422)
            ->assertJsonPath('errors.auth_factor.0', 'Para consultar pelo número da encomenda, informe os primeiros dígitos do CPF ou o e-mail de compra para validação de segurança.');
    }

    public function test_token_que_nao_e_uuid_retorna_422(): void
    {
        $this->track(['tracking_token' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['tracking_token']);
    }
}
