<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionLog;
use App\Models\Size;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Validações do CheckoutRequest (FormRequest) e do OrderCreationService (catálogo).
 * Mensagens exatas só onde o projeto define mensagem própria; para regras com mensagem
 * padrão do Laravel, afirma-se apenas a chave do erro (as mensagens padrão vêm de lang/pt_BR).
 */
#[Group('characterization')]
class CheckoutValidationTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private Product $product;

    private Color $color;

    protected function setUp(): void
    {
        parent::setUp();
        ['product' => $this->product, 'color' => $this->color] = $this->catalogItem(['title' => 'Vaso Origami']);
    }

    private function assertFirstError(TestResponse $response, string $key, string $message): void
    {
        $errors = (array) $response->json('errors');
        $this->assertArrayHasKey($key, $errors, 'Chaves de erro: '.implode(', ', array_keys($errors)));
        $this->assertSame($message, $errors[$key][0]);
    }

    private function assertNothingPersisted(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, ProductionLog::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     */
    private function postItem(array $itemOverrides = [], string $method = 'pix'): TestResponse
    {
        return $this->postJson('/api/checkout', $this->checkoutPayload(
            [$this->itemPayload($this->product, $this->color, null, 1, 'pla', $itemOverrides)],
            $method
        ));
    }

    // ---------------------------------------------------------------- catálogo (service)

    public function test_cor_sem_estoque_retorna_422_com_mensagem(): void
    {
        $this->color->update(['in_stock' => false]);

        $this->postItem()
            ->assertStatus(422)
            ->assertExactJson([
                'message' => "A cor selecionada para o produto 'Vaso Origami' está esgotada no estoque.",
                'errors' => ['items' => ["A cor selecionada para o produto 'Vaso Origami' está esgotada no estoque."]],
            ]);

        $this->assertNothingPersisted();
    }

    public function test_produto_inativo_retorna_422_com_mensagem(): void
    {
        $this->product->update(['is_active' => false]);

        $this->postItem()
            ->assertStatus(422)
            ->assertExactJson([
                'message' => "O produto #{$this->product->id} não está mais disponível no catálogo.",
                'errors' => ['items' => ["O produto #{$this->product->id} não está mais disponível no catálogo."]],
            ]);

        $this->assertNothingPersisted();
    }

    public function test_material_fora_de_allowed_materials_retorna_422(): void
    {
        $msg = "O material 'PETG' não é autorizado para fabricação da peça 'Vaso Origami'. Materiais permitidos para este modelo: PLA.";

        $this->postItem(['material' => 'petg'])
            ->assertStatus(422)
            ->assertExactJson(['message' => $msg, 'errors' => ['items' => [$msg]]]);

        $this->assertNothingPersisted();
    }

    public function test_tpu_mostra_short_label_na_mensagem(): void
    {
        $this->postItem(['material' => 'tpu'])
            ->assertStatus(422)
            ->assertJsonPath('message', "O material 'TPU Flexível' não é autorizado para fabricação da peça 'Vaso Origami'. Materiais permitidos para este modelo: PLA.");
    }

    public function test_material_fora_do_enum_retorna_422_do_form_request(): void
    {
        // A mensagem própria 'items.*.material.enum' nunca é usada: o objeto Rule\Enum devolve a
        // mensagem padrão 'validation.enum', que vem de lang/pt_BR (M-20).
        $this->postItem(['material' => 'resina'])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.material', 'O valor escolhido em material é inválido.'));

        $this->assertNothingPersisted();
    }

    public function test_material_em_maiusculas_e_rejeitado(): void
    {
        // Comportamento aceito pelo time: o enum só aceita minúsculas.
        $this->postItem(['material' => 'PLA'])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.material', 'O valor escolhido em material é inválido.'));
    }

    public function test_modificador_negativo_maior_que_o_preco_gera_unitario_negativo(): void
    {
        // PERGUNTA: não há piso para o preço unitário; base 5000 + modificador −6000 = −1000;
        // o total trava as peças em 0 (max(0, ...)), mas o unit_price_cents gravado fica negativo.
        $size = Size::factory()->modifier(-6000)->create();
        $this->product->sizes()->attach($size->id);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postItem(['size_id' => $size->id], 'credit_card')
            ->assertStatus(201)
            ->assertJsonPath('total_cents', self::SERVER_PAC_CENTS); // max(0, −1000) + frete: o subtotal é travado em 0, o unitário não

        $this->assertSame(-1000, OrderItem::sole()->unit_price_cents);
    }

    // ---------------------------------------------------------------- quantidade

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'zero' => [0, 'A quantidade mínima por peça é de 1 unidade.'],
            'negativa' => [-1, 'A quantidade mínima por peça é de 1 unidade.'],
            '51' => [51, 'A quantidade máxima por item na mesma encomenda é de 50 unidades.'],
            'gigante' => [1000000000, 'A quantidade máxima por item na mesma encomenda é de 50 unidades.'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantidade_invalida_retorna_422(mixed $quantity, string $message): void
    {
        $this->postItem(['quantity' => $quantity])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.quantity', $message));

        $this->assertNothingPersisted();
    }

    public function test_quantidade_nao_inteira_retorna_422(): void
    {
        $this->postItem(['quantity' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
        $this->postItem(['quantity' => 1.5])->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_quantidade_50_e_aceita(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postItem(['quantity' => 50])->assertStatus(201);
    }

    // ---------------------------------------------------------------- pagamento

    public function test_cartao_sem_card_token_retorna_422(): void
    {
        $payload = $this->checkoutPayload([$this->itemPayload($this->product, $this->color)], 'credit_card');
        unset($payload['payment']['card_token']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['payment.card_token']);
        $this->assertNothingPersisted();
    }

    public function test_cartao_sem_payment_method_id_e_sem_parcelas_retorna_422(): void
    {
        $payload = $this->checkoutPayload([$this->itemPayload($this->product, $this->color)], 'credit_card');
        unset($payload['payment']['payment_method_id'], $payload['payment']['installments']);

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment.payment_method_id', 'payment.installments']);
    }

    public function test_parcelas_acima_de_12_retorna_422(): void
    {
        $payload = $this->checkoutPayload([$this->itemPayload($this->product, $this->color)], 'credit_card');
        $payload['payment']['installments'] = 13;

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['payment.installments']);
    }

    public function test_forma_de_pagamento_desconhecida_retorna_422(): void
    {
        $this->postItem([], 'boleto')
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'payment.payment_method', 'Forma de pagamento inválida.'));
    }

    // ---------------------------------------------------------------- comprador

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidCpfs(): array
    {
        return [
            'dígito errado' => ['529.982.247-24', 'O CPF informado é inválido.'],
            'repetido' => ['111.111.111-11', 'O CPF informado possui um formato inválido.'],
            'curto' => ['1234567890', 'O CPF informado deve conter exatamente 11 dígitos.'],
        ];
    }

    #[DataProvider('invalidCpfs')]
    public function test_cpf_invalido_retorna_422(string $cpf, string $message): void
    {
        $this->postJson('/api/checkout', $this->checkoutPayload(
            [$this->itemPayload($this->product, $this->color)],
            'pix',
            1500,
            ['customer' => ['cpf' => $cpf]]
        ))->assertStatus(422)->tap(fn ($r) => $this->assertFirstError($r, 'customer.cpf', $message));

        $this->assertNothingPersisted();
    }

    // ---------------------------------------------------------------- IDs

    public function test_produto_inexistente_retorna_422(): void
    {
        $this->postItem(['product_id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.product_id', 'Um dos produtos informados não existe no catálogo.'));
    }

    public function test_product_id_que_nao_e_uuid_retorna_422(): void
    {
        $this->postItem(['product_id' => '123'])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.product_id', 'O identificador do produto deve ser um UUID válido.'));
    }

    public function test_cor_inexistente_retorna_422(): void
    {
        $this->postItem(['color_id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.color_id', 'A cor selecionada não foi encontrada no catálogo.'));
    }

    public function test_tamanho_inexistente_retorna_422(): void
    {
        $this->postItem(['size_id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items.0.size_id', 'A variação de tamanho selecionada não foi encontrada.'));
    }

    public function test_sacola_vazia_retorna_422(): void
    {
        $this->postJson('/api/checkout', $this->checkoutPayload([], 'pix'))
            ->assertStatus(422)
            ->tap(fn ($r) => $this->assertFirstError($r, 'items', 'A sacola de encomendas não pode estar vazia.'));
    }
}
