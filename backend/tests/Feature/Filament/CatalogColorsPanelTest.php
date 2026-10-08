<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\MaterialType;
use App\Filament\Resources\ColorResource\Pages\EditColor;
use App\Filament\Resources\ColorResource\Pages\ListColors;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\SizeResource\Pages\ListSizes;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-12: telas de cores, tamanhos e variações da peça no painel.
 * Usa a base de caracterização só pelo SQLite em memória e pela trava contra rede.
 */
class CatalogColorsPanelTest extends CharacterizationTestCase
{
    private const LINKED_COLOR_MESSAGE = 'Cor em uso no catálogo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_filtros_da_tela_de_tamanhos_so_usam_colunas_que_existem(): void
    {
        $size = Size::factory()->create();

        $component = Livewire::test(ListSizes::class)->assertCanSeeTableRecords([$size]);

        foreach (array_keys($component->instance()->getTable()->getFilters()) as $filter) {
            $this->assertTrue(Schema::hasColumn('sizes', $filter), "Filtro '{$filter}' usa coluna inexistente em sizes");
        }
    }

    public function test_lista_de_cores_da_peca_mostra_o_material_e_se_esta_esgotada(): void
    {
        $product = $this->productWith(['pla'], [Color::factory()->create(['name' => 'Lilás MIMOO'])]);
        Color::factory()->material(MaterialType::PETG)->outOfStock()->create(['name' => 'Rosa Algodão']);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSee('Lilás MIMOO · PLA')
            ->assertSee('Rosa Algodão · PETG (esgotada)');
    }

    public function test_salvar_peca_com_cor_de_material_nao_homologado_da_erro_e_nao_grava(): void
    {
        $pla = Color::factory()->create(['name' => 'Lilás MIMOO']);
        $petg = Color::factory()->material(MaterialType::PETG)->create(['name' => 'Rosa Algodão']);
        $product = $this->productWith(['pla'], [$pla]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['colors' => [$pla->getKey(), $petg->getKey()]])
            ->call('save')
            ->assertHasFormErrors(['colors'])
            ->assertSee('Estas cores são de um material que a peça não usa: Rosa Algodão (PETG)');

        $this->assertSame([$pla->getKey()], $product->colors()->pluck('colors.id')->all());
    }

    public function test_salvar_peca_com_material_sem_nenhuma_cor_da_erro(): void
    {
        $pla = Color::factory()->create();
        $product = $this->productWith(['pla'], [$pla]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['allowed_materials' => ['pla', 'petg']])
            ->call('save')
            ->assertHasFormErrors(['colors'])
            ->assertSee('Falta marcar pelo menos uma cor de: PETG');

        $this->assertSame(['pla'], $product->fresh()->allowed_materials);
    }

    public function test_salvar_peca_com_uma_cor_por_material_grava(): void
    {
        $pla = Color::factory()->create();
        $petg = Color::factory()->material(MaterialType::PETG)->create();
        $product = $this->productWith(['pla'], [$pla]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['allowed_materials' => ['pla', 'petg'], 'colors' => [$pla->getKey(), $petg->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([$pla->getKey(), $petg->getKey()], $product->colors()->pluck('colors.id')->all());
    }

    public function test_cor_usada_em_peca_nao_se_apaga_pela_lista(): void
    {
        $color = Color::factory()->create();
        $this->productWith(['pla'], [$color]);

        Livewire::test(ListColors::class)
            ->callTableAction(TableDeleteAction::class, $color)
            ->assertNotified(self::LINKED_COLOR_MESSAGE);

        $this->assertModelExists($color);
    }

    public function test_cor_sem_peca_se_apaga_pela_lista(): void
    {
        $color = Color::factory()->create();

        Livewire::test(ListColors::class)->callTableAction(TableDeleteAction::class, $color);

        $this->assertModelMissing($color);
    }

    public function test_apagar_em_massa_com_uma_cor_usada_nao_apaga_nenhuma(): void
    {
        $linked = Color::factory()->create();
        $free = Color::factory()->create();
        $this->productWith(['pla'], [$linked]);

        Livewire::test(ListColors::class)
            ->callTableBulkAction('delete', [$linked, $free])
            ->assertNotified(self::LINKED_COLOR_MESSAGE);

        $this->assertModelExists($linked);
        $this->assertModelExists($free);
    }

    public function test_cor_usada_em_peca_nao_se_apaga_pela_tela_de_edicao(): void
    {
        $color = Color::factory()->create();
        $this->productWith(['pla'], [$color]);

        Livewire::test(EditColor::class, ['record' => $color->getKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified(self::LINKED_COLOR_MESSAGE);

        $this->assertModelExists($color);
    }

    /**
     * @param  array<int, string>  $materials
     * @param  array<int, Color>  $colors
     */
    private function productWith(array $materials, array $colors): Product
    {
        $product = Product::factory()->materials($materials)->create();
        $product->categories()->attach(Category::factory()->create());
        $product->sizes()->attach(Size::factory()->create());
        $product->colors()->attach(collect($colors)->map->getKey()->all());

        return $product;
    }
}
