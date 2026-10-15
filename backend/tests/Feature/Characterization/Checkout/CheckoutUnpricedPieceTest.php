<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Size;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-29: peça com preço base + acréscimo do tamanho abaixo de 1 centavo não é vendável.
 * O checkout recusa com 422 (antes, a peça negativa virava desconto nas outras peças do pedido)
 * e o catálogo não mostra o tamanho que o checkout recusaria.
 */
#[Group('characterization')]
class CheckoutUnpricedPieceTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private function assertNothingPersisted(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_peca_negativa_junto_com_outra_peca_nao_vira_desconto(): void
    {
        ['product' => $a, 'color' => $colorA] = $this->catalogItem(['title' => 'Vaso Origami', 'base_price_cents' => 5000]);
        ['product' => $b, 'color' => $colorB, 'size' => $size] = $this->catalogItem(['title' => 'Gato Lua', 'base_price_cents' => 5000], -6000);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            $this->itemPayload($a, $colorA),
            $this->itemPayload($b, $colorB, $size),
        ], 'credit_card'));

        $response->assertStatus(422);
        $this->assertSame(
            ["O tamanho {$size->name} de Gato Lua está indisponível no momento. Tire do carrinho ou escolha outro tamanho."],
            $response->json('errors')['items.1.size_id']
        );
        $this->assertNothingPersisted();
    }

    /** @return array<string, array{string}> */
    public static function metodos(): array
    {
        return ['pix' => ['pix'], 'cartão' => ['credit_card']];
    }

    #[DataProvider('metodos')]
    public function test_peca_com_preco_zero_retorna_422(string $method): void
    {
        ['product' => $product, 'color' => $color, 'size' => $size] = $this->catalogItem(['title' => 'Vaso Origami', 'base_price_cents' => 5000], -5000);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, $size)], $method));

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.size_id']);
        $this->assertNothingPersisted();
    }

    public function test_peca_sem_tamanho_com_preco_base_zero_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['title' => 'Vaso Origami', 'base_price_cents' => 0]);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card'));

        $response->assertStatus(422);
        $this->assertSame(
            ['A peça Vaso Origami está indisponível no momento. Tire do carrinho para continuar.'],
            $response->json('errors')['items.0.product_id']
        );
        $this->assertNothingPersisted();
    }

    /** @return array<string, array{string, int}> */
    public static function precoMinimo(): array
    {
        // 1 centavo: PIX desconta round(1 × 0,10) = 0
        return ['pix' => ['pix', 1], 'cartão' => ['credit_card', 1]];
    }

    #[DataProvider('precoMinimo')]
    public function test_peca_de_um_centavo_e_aceita_e_cobra_o_valor_certo(string $method, int $piecesCents): void
    {
        ['product' => $product, 'color' => $color, 'size' => $size] = $this->catalogItem(['base_price_cents' => 5000], -4999);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, $method === 'pix' ? 'pending' : 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, $size)], $method))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', $piecesCents + self::SERVER_PAC_CENTS);

        $this->assertSame(1, OrderItem::sole()->unit_price_cents);
    }

    // ---------------------------------------------------------------- catálogo

    /**
     * @return array<string, mixed>
     */
    private function catalogProduct(string $productId): array
    {
        // A coleção do catálogo sai sem o envelope "data"
        $products = collect($this->getJson('/api/products')->assertOk()->json());

        return $products->firstWhere('id', $productId);
    }

    public function test_catalogo_omite_o_tamanho_que_deixaria_a_peca_sem_preco(): void
    {
        ['product' => $product] = $this->catalogItem(['base_price_cents' => 5000]);
        $valid = Size::factory()->modifier(-4999)->create(['name' => 'Mini']);
        $unpriced = Size::factory()->modifier(-5000)->create(['name' => 'Micro']);
        $product->sizes()->attach([$valid->id, $unpriced->id]);

        $data = $this->catalogProduct($product->id);

        $this->assertSame([$valid->id], array_column($data['sizes'], 'id'));
        $this->assertSame([$valid->id], array_column($data['sizeOptions'], 'id'));
    }

    public function test_catalogo_com_todos_os_tamanhos_sem_preco_devolve_o_produto_sem_tamanhos(): void
    {
        ['product' => $product] = $this->catalogItem(['base_price_cents' => 5000], -6000);

        $data = $this->catalogProduct($product->id);

        $this->assertSame([], $data['sizes']);
        // Mesmo comportamento de produto sem tamanho ligado: "sizeOptions" traz o tamanho padrão do front (id "p", sem acréscimo)
        $this->assertSame(['p'], array_column($data['sizeOptions'], 'id'));
        $this->assertSame(0, $data['sizeOptions'][0]['price_modifier_cents']);
    }
}
