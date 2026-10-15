<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\SizeResource\Pages\ListSizes;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-29: o painel não deixa salvar um tamanho ou uma peça cuja combinação (preço base + acréscimo)
 * fique abaixo de R$ 0,01, porque o checkout recusaria a compra.
 */
class PanelUnpricedPieceTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tamanho_que_deixa_peca_ligada_sem_preco_da_erro_com_o_nome_da_peca(): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => 0]);
        $this->sellableProduct(5000, $size, 'Vaso Origami');
        $this->sellableProduct(9000, $size, 'Gato Lua');

        Livewire::test(ListSizes::class)
            ->callTableAction('edit', $size, data: ['price_modifier_in_cents' => '-60,00'])
            ->assertHasTableActionErrors(['price_modifier_in_cents'])
            ->assertSee('Com esse valor, estas peças ficariam sem preço: Vaso Origami (R$ 50,00). Use um desconto menor ou tire o tamanho dessas peças.');

        $this->assertSame(0, $size->fresh()->price_modifier_in_cents);
    }

    public function test_tamanho_que_deixa_peca_ligada_com_preco_zero_da_erro(): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => 0]);
        $this->sellableProduct(5000, $size);

        Livewire::test(ListSizes::class)
            ->callTableAction('edit', $size, data: ['price_modifier_in_cents' => '-50,00'])
            ->assertHasTableActionErrors(['price_modifier_in_cents']);

        $this->assertSame(0, $size->fresh()->price_modifier_in_cents);
    }

    public function test_tamanho_com_desconto_que_mantem_as_pecas_com_preco_salva(): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => 0]);
        $this->sellableProduct(5000, $size);

        Livewire::test(ListSizes::class)
            ->callTableAction('edit', $size, data: ['price_modifier_in_cents' => '-49,99'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(-4999, $size->fresh()->price_modifier_in_cents);
    }

    public function test_tamanho_negativo_sem_pecas_ligadas_salva(): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => 0]);

        Livewire::test(ListSizes::class)
            ->callTableAction('edit', $size, data: ['price_modifier_in_cents' => '-60,00'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(-6000, $size->fresh()->price_modifier_in_cents);
    }

    public function test_peca_com_preco_base_zero_da_erro(): void
    {
        $product = $this->sellableProduct(5000, Size::factory()->create(['price_modifier_in_cents' => 0]));

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['base_price_cents' => '0,00'])
            ->call('save')
            ->assertHasFormErrors(['base_price_cents'])
            ->assertSee('O preço base precisa ser de pelo menos R$ 0,01.');

        $this->assertSame(5000, $product->fresh()->base_price_cents);
    }

    public function test_peca_com_tamanho_que_a_deixa_sem_preco_da_erro_com_o_nome_do_tamanho(): void
    {
        $product = $this->sellableProduct(9000, Size::factory()->create(['price_modifier_in_cents' => 0]));
        $micro = Size::factory()->create(['name' => 'Micro', 'price_modifier_in_cents' => -6000]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['base_price_cents' => '50,00', 'sizes' => [$micro->id]])
            ->call('save')
            ->assertHasFormErrors(['sizes'])
            ->assertSee('Com o preço base de R$ 50,00, estes tamanhos deixariam a peça sem preço: Micro (R$ -60,00). Aumente o preço base ou desmarque o tamanho.');

        $this->assertSame(9000, $product->fresh()->base_price_cents);
    }

    public function test_peca_com_tamanho_que_mantem_o_preco_salva(): void
    {
        $product = $this->sellableProduct(9000, Size::factory()->create(['price_modifier_in_cents' => 0]));
        $mini = Size::factory()->create(['price_modifier_in_cents' => -4999]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['base_price_cents' => '50,00', 'sizes' => [$mini->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5000, $product->fresh()->base_price_cents);
    }

    private function sellableProduct(int $basePriceCents, Size $size, string $title = 'Peça'): Product
    {
        $product = Product::factory()->materials(['pla'])->create(['title' => $title, 'base_price_cents' => $basePriceCents]);
        $product->categories()->attach(Category::factory()->create());
        $product->sizes()->attach($size);
        $product->colors()->attach(Color::factory()->create());

        return $product;
    }
}
