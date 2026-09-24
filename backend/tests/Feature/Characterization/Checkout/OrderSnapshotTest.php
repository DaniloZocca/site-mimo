<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\MaterialType;
use App\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Snapshots imutáveis em order_items.
 */
#[Group('characterization')]
class OrderSnapshotTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    public function test_snapshots_gravados_e_imunes_a_alteracoes_do_catalogo(): void
    {
        ['product' => $product, 'color' => $color, 'size' => $size] = $this->catalogItem([
            'title' => 'Dragão Articulado',
            'base_price_cents' => 8000,
            'allowed_materials' => ['pla', 'petg'],
        ], 2000);
        $color->update(['name' => 'Roxo MIMOO']);
        $size->update(['name' => 'Grande (18cm)']);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload(
            [$this->itemPayload($product, $color, $size, 2, 'petg')],
            'credit_card'
        ))->assertStatus(201);

        $expected = [
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'product_title_snapshot' => 'Dragão Articulado',
            'color_name_snapshot' => 'Roxo MIMOO',
            'size_name_snapshot' => 'Grande (18cm)',
            'material_name_snapshot' => 'PETG Resistente (Uso Externo & Térmico)',
            'unit_price_cents' => 10000,
            'quantity' => 2,
        ];
        $order = Order::sole();
        $this->assertSame($expected, $order->items()->sole()->only(array_keys($expected)));
        $totalBefore = $order->total_cents;

        // Alterações posteriores no catálogo
        $product->update(['title' => 'Dragão V2', 'base_price_cents' => 1]);
        $color->update(['name' => 'Verde']);
        $size->update(['name' => 'Mini', 'price_modifier_in_cents' => 0]);

        $order->refresh();
        $this->assertSame($expected, $order->items()->sole()->only(array_keys($expected)));
        $this->assertSame($totalBefore, $order->total_cents);
    }

    public function test_item_sem_tamanho_grava_tamanho_padrao_e_size_id_nulo(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card'))->assertStatus(201);

        $item = Order::sole()->items()->sole();
        $this->assertNull($item->size_id);
        $this->assertSame('Tamanho Padrão', $item->size_name_snapshot);
        $this->assertSame(MaterialType::PLA->label(), $item->material_name_snapshot);
    }

    public function test_excluir_produto_vendido_anula_product_id_e_mantem_snapshot(): void
    {
        // FK order_items.product_id com nullOnDelete (migration 2026_02_01_000008).
        ['product' => $product, 'color' => $color] = $this->catalogItem(['title' => 'Vaso Origami']);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));
        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card'))->assertStatus(201);

        $product->delete();

        $item = Order::sole()->items()->sole();
        $this->assertNull($item->product_id);
        $this->assertSame('Vaso Origami', $item->product_title_snapshot);
    }
}
