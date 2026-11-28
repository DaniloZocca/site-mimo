<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Filament\Resources\ColorResource\Pages\CreateColor;
use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\MaterialResource\Pages\CreateMaterial;
use App\Filament\Resources\MaterialResource\Pages\EditMaterial;
use App\Filament\Resources\MaterialResource\Pages\ListMaterials;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Livewire\Livewire;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-84: aba "Materiais" do painel. Remover = desativar; apagar só sem uso; código gerado e imutável.
 */
class MaterialsPanelTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'short_name' => 'PLA Silk',
            'full_name' => 'PLA Silk (Acabamento Acetinado)',
            'description' => 'Brilho acetinado, ótimo para colecionáveis.',
            'sort_order' => 5,
            'is_active' => true,
        ];
    }

    public function test_lista_mostra_os_materiais_e_o_texto_de_ajuda_aparece_no_formulario(): void
    {
        Livewire::test(ListMaterials::class)
            ->assertCanSeeTableRecords(Material::query()->get())
            ->assertSee('TPU Flexível');

        Livewire::test(CreateMaterial::class)
            ->assertSee(MaterialResource::HELP_TEXT)
            ->assertSee('Mudar o nome ou a descrição muda o que a loja mostra; pedidos antigos continuam com o nome da época.');
    }

    public function test_cria_material_com_codigo_gerado_do_nome_curto(): void
    {
        Livewire::test(CreateMaterial::class)
            ->fillForm($this->form(['short_name' => 'PLA Silk Pérola', 'code' => 'outro-codigo']))
            ->call('create')
            ->assertHasNoFormErrors();

        $material = Material::query()->where('short_name', 'PLA Silk Pérola')->sole();
        $this->assertSame('pla-silk-perola', $material->code);
        $this->assertTrue($material->is_active);
    }

    public function test_codigo_tem_no_maximo_30_caracteres_so_com_letras_numeros_e_hifen(): void
    {
        $this->assertSame('pla-super-brilhante-acetinado', Material::codeFromName('PLA Super Brilhante Acetinado Especial'));
        $this->assertSame(30, strlen(Material::codeFromName(str_repeat('a', 45))));
        $this->assertSame('tpu-95a', Material::codeFromName('  TPU 95A!! '));
        $this->assertMatchesRegularExpression(Material::CODE_PATTERN, Material::codeFromName('Ação & Cor ✨ 2'));
    }

    public function test_codigo_repetido_da_erro_claro_e_nao_cria(): void
    {
        Livewire::test(CreateMaterial::class)
            ->fillForm($this->form(['short_name' => 'Petg']))
            ->call('create')
            ->assertHasFormErrors(['short_name'])
            ->assertSee('Já existe um material com o código');

        $this->assertSame(1, Material::query()->where('code', 'petg')->count());
    }

    public function test_nome_sem_letra_nem_numero_da_erro(): void
    {
        Livewire::test(CreateMaterial::class)
            ->fillForm($this->form(['short_name' => '✨✨']))
            ->call('create')
            ->assertHasFormErrors(['short_name']);

        $this->assertSame(4, Material::count());
    }

    public function test_codigo_nao_muda_na_edicao(): void
    {
        $material = Material::query()->where('code', 'abs')->sole();

        Livewire::test(EditMaterial::class, ['record' => $material->getKey()])
            ->fillForm(['short_name' => 'ABS Plus', 'code' => 'abs-plus'])
            ->call('save')
            ->assertHasNoFormErrors();

        $material->refresh();
        $this->assertSame('abs', $material->code);
        $this->assertSame('ABS Plus', $material->short_name);

        $material->update(['code' => 'mudou']);
        $this->assertSame('abs', $material->fresh()->code);
    }

    public function test_texto_com_alegacao_ambiental_proibida_e_recusado(): void
    {
        Livewire::test(CreateMaterial::class)
            ->fillForm($this->form(['description' => 'Filamento ecológico e 100% biodegradável.']))
            ->call('create')
            ->assertHasFormErrors(['description']);

        Livewire::test(CreateMaterial::class)
            ->fillForm($this->form(['full_name' => 'PLA Sustentável']))
            ->call('create')
            ->assertHasFormErrors(['full_name']);

        $this->assertSame(0, Material::query()->where('short_name', 'PLA Silk')->count());
    }

    public function test_desativar_esconde_das_opcoes_de_peca_e_cor_e_reativar_volta(): void
    {
        $material = Material::query()->where('code', 'abs')->sole();

        Livewire::test(ListMaterials::class)->callTableAction('deactivate', $material);
        $this->assertFalse($material->fresh()->is_active);

        Livewire::test(CreateColor::class)->assertDontSee('ABS Industrial');
        $product = $this->product(['pla'], [Color::factory()->create()]);
        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertDontSee('ABS Industrial')
            ->assertSee('PETG Resistente');

        Livewire::test(ListMaterials::class)->callTableAction('activate', $material->fresh());
        $this->assertTrue($material->fresh()->is_active);
        Livewire::test(CreateColor::class)->assertSee('ABS Industrial');
    }

    public function test_cor_nao_pode_ser_gravada_com_material_desativado(): void
    {
        Material::query()->where('code', 'abs')->update(['is_active' => false]);

        Livewire::test(CreateColor::class)
            ->fillForm(['name' => 'Cinza', 'hex_code' => '#888888', 'material_type' => 'abs', 'in_stock' => true])
            ->call('create')
            ->assertHasFormErrors(['material_type']);

        $this->assertSame(0, Color::query()->where('name', 'Cinza')->count());
    }

    public function test_cor_nova_pode_usar_material_novo(): void
    {
        Material::factory()->create(['code' => 'pla-silk', 'short_name' => 'PLA Silk', 'full_name' => 'PLA Silk (Acetinado)']);

        Livewire::test(CreateColor::class)
            ->fillForm(['name' => 'Pérola', 'hex_code' => '#EEEEEE', 'material_type' => 'pla-silk', 'in_stock' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('pla-silk', Color::query()->where('name', 'Pérola')->sole()->material_type);
    }

    public function test_material_em_uso_por_cor_nao_se_apaga(): void
    {
        $material = Material::query()->where('code', 'petg')->sole();
        Color::factory()->material('petg')->create();

        Livewire::test(ListMaterials::class)->assertTableActionHidden(TableDeleteAction::class, $material);
        Livewire::test(EditMaterial::class, ['record' => $material->getKey()])->assertActionHidden(DeleteAction::class);

        $this->assertFalse($material->delete());
        $this->assertModelExists($material);
    }

    public function test_material_em_uso_por_peca_nao_se_apaga(): void
    {
        $material = Material::query()->where('code', 'tpu')->sole();
        Product::factory()->materials(['pla', 'tpu'])->create();

        Livewire::test(ListMaterials::class)->assertTableActionHidden(TableDeleteAction::class, $material);
        $this->assertFalse($material->delete());
        $this->assertModelExists($material);
    }

    public function test_material_sem_uso_se_apaga(): void
    {
        $material = Material::factory()->create();

        Livewire::test(ListMaterials::class)->callTableAction(TableDeleteAction::class, $material);

        $this->assertModelMissing($material);
    }

    public function test_peca_aceita_material_novo_com_cor_dele(): void
    {
        Material::factory()->create(['code' => 'pla-silk', 'short_name' => 'PLA Silk', 'full_name' => 'PLA Silk (Acetinado)']);
        $pla = Color::factory()->create();
        $silk = Color::factory()->material('pla-silk')->create(['name' => 'Pérola']);
        $product = $this->product(['pla'], [$pla]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSee('Pérola · PLA Silk')
            ->fillForm(['allowed_materials' => ['pla', 'pla-silk']])
            ->call('save')
            ->assertHasFormErrors(['colors'])
            ->assertSee('Falta marcar pelo menos uma cor de: PLA Silk');

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['allowed_materials' => ['pla', 'pla-silk'], 'colors' => [$pla->getKey(), $silk->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['pla', 'pla-silk'], $product->fresh()->allowed_materials);
    }

    /**
     * @param  array<int, string>  $materials
     * @param  array<int, Color>  $colors
     */
    private function product(array $materials, array $colors): Product
    {
        $product = Product::factory()->materials($materials)->create();
        $product->categories()->attach(Category::factory()->create());
        $product->sizes()->attach(Size::factory()->create());
        $product->colors()->attach(collect($colors)->map->getKey()->all());

        return $product;
    }
}
