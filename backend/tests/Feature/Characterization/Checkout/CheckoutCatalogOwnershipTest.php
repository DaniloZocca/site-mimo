<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\MaterialType;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Cor, tamanho e material de cada item precisam pertencer ao produto (bugs #5, #6 e #19).
 * Erros por item: items.N.color_id / items.N.size_id / items.N.material, sem criar pedido
 * nem chamar o gateway.
 */
#[Group('characterization')]
class CheckoutCatalogOwnershipTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private Product $product;

    private Color $color;

    protected function setUp(): void
    {
        parent::setUp();
        ['product' => $this->product, 'color' => $this->color] = $this->catalogItem(['title' => 'Vaquinha Articulada']);
        $this->color->update(['name' => 'Seda Dourada']);
    }

    private function assertItemError(TestResponse $response, string $key, string $message): void
    {
        $response->assertStatus(422)->assertExactJson(['message' => $message, 'errors' => [$key => [$message]]]);
    }

    private function assertNothingPersisted(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postItem(array $overrides = [], string $method = 'pix'): TestResponse
    {
        return $this->postJson('/api/checkout', $this->checkoutPayload(
            [$this->itemPayload($this->product, $this->color, null, 1, 'pla', $overrides)],
            $method
        ));
    }

    // ---------------------------------------------------------------- cor (bug #5)

    public function test_cor_nao_vinculada_ao_produto_retorna_422(): void
    {
        $foreign = Color::factory()->create(['name' => 'Verde Menta']);

        $this->assertItemError(
            $this->postItem(['color_id' => $foreign->id]),
            'items.0.color_id',
            'A cor Verde Menta não está disponível para Vaquinha Articulada.'
        );
        $this->assertNothingPersisted();
    }

    public function test_produto_sem_nenhuma_cor_ligada_retorna_422(): void
    {
        $this->product->colors()->detach();

        $this->assertItemError(
            $this->postItem(),
            'items.0.color_id',
            'A cor Seda Dourada não está disponível para Vaquinha Articulada.'
        );
        $this->assertNothingPersisted();
    }

    // ---------------------------------------------------------------- tamanho (bug #6)

    public function test_tamanho_nao_vinculado_ao_produto_retorna_422(): void
    {
        $foreign = Size::factory()->modifier(-4000)->create(['name' => 'Mini']);

        $this->assertItemError(
            $this->postItem(['size_id' => $foreign->id], 'credit_card'),
            'items.0.size_id',
            'O tamanho Mini não está disponível para Vaquinha Articulada.'
        );
        $this->assertNothingPersisted();
    }

    public function test_produto_com_tamanhos_ligados_e_item_sem_tamanho_retorna_422(): void
    {
        $this->product->sizes()->attach(Size::factory()->modifier(1500)->create()->id);

        $this->assertItemError(
            $this->postItem(['size_id' => null]),
            'items.0.size_id',
            'Escolha um tamanho para Vaquinha Articulada.'
        );
        $this->assertNothingPersisted();
    }

    public function test_produto_sem_tamanhos_ligados_e_item_sem_tamanho_usa_tamanho_padrao(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postItem(['size_id' => null])->assertStatus(201);

        $item = OrderItem::sole();
        $this->assertNull($item->size_id);
        $this->assertSame('Tamanho Padrão', $item->size_name_snapshot);
        $this->assertSame((int) $this->product->base_price_cents, $item->unit_price_cents);
    }

    public function test_tamanho_ligado_ao_produto_e_aceito_com_modificador(): void
    {
        $size = Size::factory()->modifier(1500)->create();
        $this->product->sizes()->attach($size->id);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postItem(['size_id' => $size->id])->assertStatus(201);

        $this->assertSame((int) $this->product->base_price_cents + 1500, OrderItem::sole()->unit_price_cents);
    }

    // ---------------------------------------------------------------- material (bug #19)

    public function test_cor_de_filamento_diferente_do_material_escolhido_retorna_422(): void
    {
        $this->color->update(['material_type' => MaterialType::PETG]);

        $this->assertItemError(
            $this->postItem(['material' => 'pla']),
            'items.0.material',
            'A cor Seda Dourada não está disponível em PLA para Vaquinha Articulada.'
        );
        $this->assertNothingPersisted();
    }

    public function test_cor_do_mesmo_material_escolhido_e_aceita(): void
    {
        $this->product->update(['allowed_materials' => ['pla', 'petg']]);
        $this->color->update(['material_type' => MaterialType::PETG]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postItem(['material' => 'petg'])->assertStatus(201);
    }

    // ---------------------------------------------------------------- carrinho com vários itens

    public function test_carrinho_com_segundo_item_invalido_aponta_items_1_e_nao_cria_pedido(): void
    {
        ['product' => $other] = $this->catalogItem(['title' => 'Dragão Articulado']);
        $foreign = Color::factory()->create(['name' => 'Verde Menta']);

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            $this->itemPayload($this->product, $this->color),
            $this->itemPayload($other, $foreign),
        ]));

        $this->assertItemError($response, 'items.1.color_id', 'A cor Verde Menta não está disponível para Dragão Articulado.');
        $this->assertNothingPersisted();
    }
}
