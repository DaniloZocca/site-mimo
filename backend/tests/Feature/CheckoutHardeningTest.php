<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CheckoutItemDTO;
use App\DTOs\CheckoutOrderDTO;
use App\Enums\MaterialType;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private OrderCreationService $orderCreationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderCreationService = app(OrderCreationService::class);
    }

    /**
     * Teste 1: Criação de pedido persiste snapshots imutáveis e calcula o preço Zero-Trust.
     */
    public function test_order_creation_persists_immutable_snapshots_and_calculates_prices_correctly(): void
    {
        $category = Category::create([
            'name' => 'Decoração 3D',
            'slug' => 'decoracao-3d-' . Str::random(6),
        ]);

        $product = Product::create([
            'title'                      => 'Vaso Minimalista Origami',
            'slug'                       => 'vaso-origami-' . Str::random(6),
            'base_price_cents'           => 5000, // R$ 50,00
            'weight_grams'               => 150,
            'estimated_print_time_hours' => 8.5,
            'allowed_materials'          => ['pla', 'petg'],
            'is_active'                  => true,
        ]);

        $color = Color::create([
            'name'          => 'Lilás Pastel',
            'hex_code'      => '#D8B4FE',
            'material_type' => 'pla',
            'in_stock'      => true,
        ]);

        $size = Size::create([
            'name'                    => 'Grande (18cm)',
            'price_modifier_in_cents' => 1500, // + R$ 15,00
        ]);

        $itemDTO = new CheckoutItemDTO(
            productId: $product->id,
            colorId:   $color->id,
            sizeId:    $size->id,
            quantity:  2,
            material:  'petg',
        );

        $orderDTO = new CheckoutOrderDTO(
            customerName:      'Lucas Ferreira',
            customerEmail:     'lucas@exemplo.com',
            customerCpf:       '12345678909',
            customerPhone:     '11988887777',
            shippingAddress:   [
                'zip_code'     => '01001000',
                'street'       => 'Praça da Sé',
                'number'       => '100',
                'neighborhood' => 'Sé',
                'city'         => 'São Paulo',
                'state'        => 'SP',
            ],
            shippingCostCents: 1490, // R$ 14,90
            items:             [$itemDTO],
            paymentMethod:     'credit_card',
        );

        $order = $this->orderCreationService->createOrder($orderDTO);

        $this->assertNotNull($order->id);
        $this->assertStringStartsWith('MIMOO-', $order->order_number);
        $this->assertCount(1, $order->items);

        $orderItem = $order->items->first();

        // 1. Auditoria Transacional: Validação estrita de snapshots imutáveis
        $this->assertEquals($product->title, $orderItem->product_title_snapshot);
        $this->assertEquals('Lilás Pastel', $orderItem->color_name_snapshot);
        $this->assertEquals('Grande (18cm)', $orderItem->size_name_snapshot);
        $this->assertEquals(MaterialType::PETG->label(), $orderItem->material_name_snapshot);

        // 2. Recálculo Zero-Trust: Unitário = 5000 + 1500 = 6500 centavos
        $this->assertEquals(6500, $orderItem->unit_price_cents);
        $this->assertEquals(2, $orderItem->quantity);

        // 3. Total sem desconto (Cartão): (6500 * 2) + 1490 = 13000 + 1490 = 14490 centavos
        $this->assertEquals(14490, $order->total_cents);
        $this->assertEquals(1490, $order->shipping_cost_cents);
    }

    /**
     * Teste 2: Pagamento via PIX aplica 10% de desconto no valor das peças antes de somar o frete.
     */
    public function test_order_creation_applies_ten_percent_discount_on_pieces_for_pix(): void
    {
        $product = Product::create([
            'title'                      => 'Luminária Lua 3D',
            'slug'                       => 'luminaria-lua-' . Str::random(6),
            'base_price_cents'           => 10000, // R$ 100,00
            'weight_grams'               => 300,
            'estimated_print_time_hours' => 14.0,
            'allowed_materials'          => ['pla'],
            'is_active'                  => true,
        ]);

        $color = Color::create([
            'name'          => 'Branco Puro',
            'hex_code'      => '#FFFFFF',
            'material_type' => 'pla',
            'in_stock'      => true,
        ]);

        $itemDTO = new CheckoutItemDTO(
            productId: $product->id,
            colorId:   $color->id,
            sizeId:    null,
            quantity:  1,
            material:  'pla',
        );

        $orderDTO = new CheckoutOrderDTO(
            customerName:      'Mariana Costa',
            customerEmail:     'mariana@exemplo.com',
            customerCpf:       '98765432100',
            customerPhone:     '11977776666',
            shippingAddress:   [
                'zip_code'     => '04001000',
                'street'       => 'Av. Paulista',
                'number'       => '1000',
                'neighborhood' => 'Bela Vista',
                'city'         => 'São Paulo',
                'state'        => 'SP',
            ],
            shippingCostCents: 2000, // R$ 20,00
            items:             [$itemDTO],
            paymentMethod:     'pix', // PIX com 10% OFF
        );

        $order = $this->orderCreationService->createOrder($orderDTO);

        // Subtotal peças = R$ 100,00 (10000 centavos)
        // Desconto PIX 10% = R$ 10,00 (1000 centavos)
        // Subtotal peças com desconto = R$ 90,00 (9000 centavos)
        // Frete = R$ 20,00 (2000 centavos)
        // Total = 9000 + 2000 = 11000 centavos
        $this->assertEquals(11000, $order->total_cents);
        $this->assertEquals(2000, $order->shipping_cost_cents);
    }

    /**
     * Teste 3: Impede a compra de materiais desautorizados para o modelo 3D lançando ValidationException.
     */
    public function test_order_creation_throws_validation_exception_when_material_is_not_allowed(): void
    {
        $product = Product::create([
            'title'                      => 'Suporte Gamer Articulado',
            'slug'                       => 'suporte-gamer-' . Str::random(6),
            'base_price_cents'           => 8000,
            'weight_grams'               => 200,
            'estimated_print_time_hours' => 10.0,
            'allowed_materials'          => ['petg', 'abs'], // Apenas PETG e ABS
            'is_active'                  => true,
        ]);

        $color = Color::create([
            'name'          => 'Preto Galáxia',
            'hex_code'      => '#1E1E2E',
            'material_type' => 'petg',
            'in_stock'      => true,
        ]);

        // Cliente tenta comprar com material 'tpu' (não homologado para esta peça)
        $itemDTO = new CheckoutItemDTO(
            productId: $product->id,
            colorId:   $color->id,
            sizeId:    null,
            quantity:  1,
            material:  'tpu',
        );

        $orderDTO = new CheckoutOrderDTO(
            customerName:      'Carlos Eduardo',
            customerEmail:     'carlos@exemplo.com',
            customerCpf:       '11122233344',
            customerPhone:     '11955554444',
            shippingAddress:   [
                'zip_code'     => '01001000',
                'street'       => 'Rua das Flores',
                'number'       => '12',
                'neighborhood' => 'Centro',
                'city'         => 'São Paulo',
                'state'        => 'SP',
            ],
            shippingCostCents: 1500,
            items:             [$itemDTO],
            paymentMethod:     'credit_card',
        );

        $this->expectException(ValidationException::class);

        $this->orderCreationService->createOrder($orderDTO);
    }
}
