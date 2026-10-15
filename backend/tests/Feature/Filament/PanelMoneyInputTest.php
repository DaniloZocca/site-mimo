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
use App\Rules\ValidMoneyInput;
use App\Support\MoneyInput;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-30: campos de dinheiro do painel (preço base da peça e acréscimo do tamanho) aceitam vírgula e ponto
 * como separador decimal, convertem só com inteiros e recusam o que é ambíguo em vez de gravar errado.
 */
class PanelMoneyInputTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, array{string, int}> */
    public static function valoresValidos(): array
    {
        return [
            '5,50' => ['5,50', 550],
            '5.50' => ['5.50', 550],
            '1.234,56' => ['1.234,56', 123456],
            '1234,56' => ['1234,56', 123456],
            '49,9' => ['49,9', 4990],
            '0,01' => ['0,01', 1],
            '5.5' => ['5.5', 550],
            '49.9' => ['49.9', 4990],
            '1,234.56' => ['1,234.56', 123456],
            '1234,00' => ['1234,00', 123400],
            '1.234,00' => ['1.234,00', 123400],
            '1.000.000,00' => ['1.000.000,00', 100000000],
            '50' => ['50', 5000],
            'R$ 10,00' => ['R$ 10,00', 1000],
            ' 7,25 ' => [' 7,25 ', 725],
            '-5,50' => ['-5,50', -550],
        ];
    }

    #[DataProvider('valoresValidos')]
    public function test_converte_o_valor_digitado_em_centavos(string $digitado, int $centavos): void
    {
        $this->assertSame($centavos, MoneyInput::toCents($digitado));
    }

    /** @return array<string, array{string}> */
    public static function valoresRecusados(): array
    {
        return [
            'letras' => ['abc'],
            'vazio' => [''],
            'só R$' => ['R$'],
            'milhar sem centavos (ponto)' => ['1.234'],
            'milhar sem centavos (vírgula)' => ['1,234'],
            'três casas' => ['5,555'],
            'mesmo separador no milhar e nos centavos' => ['1.234.56'],
            'grupo de milhar errado' => ['12.34,56'],
            'separador no fim' => ['5,'],
            'dois sinais' => ['--5'],
            'acima do que a coluna aguenta' => ['99999999,00'],
        ];
    }

    #[DataProvider('valoresRecusados')]
    public function test_recusa_valor_ambiguo_ou_que_nao_e_numero(string $digitado): void
    {
        $this->assertNull(MoneyInput::toCents($digitado));
    }

    public function test_exibe_sempre_duas_casas_decimais(): void
    {
        $this->assertSame('1.234,00', MoneyInput::format(123400));
        $this->assertSame('1.000.000,00', MoneyInput::format(100000000));
        $this->assertSame('50,00', MoneyInput::format(5000));
        $this->assertSame('0,01', MoneyInput::format(1));
        $this->assertSame('0,00', MoneyInput::format(null));
        $this->assertSame('-5,50', MoneyInput::format(-550));
    }

    /** @return array<string, array{string, int}> */
    public static function valoresPedidosPeloZocca(): array
    {
        return [
            '5,50' => ['5,50', 550],
            '5.50' => ['5.50', 550],
            '1.234,56' => ['1.234,56', 123456],
            '1234,56' => ['1234,56', 123456],
            '49,9' => ['49,9', 4990],
            '0,01' => ['0,01', 1],
        ];
    }

    #[DataProvider('valoresPedidosPeloZocca')]
    public function test_salvar_preco_base_da_peca_pelo_painel(string $digitado, int $centavos): void
    {
        $product = $this->sellableProduct(9990);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['base_price_cents' => $digitado])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($centavos, $product->fresh()->base_price_cents);
    }

    public function test_salvar_acrescimo_do_tamanho_pelo_painel(): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => 0]);

        foreach (['5.50' => 550, '5,50' => 550, '12.5' => 1250] as $digitado => $centavos) {
            Livewire::test(ListSizes::class)
                ->callTableAction('edit', $size, data: ['price_modifier_in_cents' => (string) $digitado])
                ->assertHasNoTableActionErrors();

            $this->assertSame($centavos, $size->fresh()->price_modifier_in_cents, "Digitado: {$digitado}");
        }
    }

    public function test_acrescimo_invalido_do_tamanho_da_erro_e_nao_grava(): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => 1500]);

        Livewire::test(ListSizes::class)
            ->callTableAction('edit', $size, data: ['price_modifier_in_cents' => '1.500'])
            ->assertHasTableActionErrors(['price_modifier_in_cents']);

        $this->assertSame(1500, $size->fresh()->price_modifier_in_cents);
    }

    /** @return array<string, array{string}> */
    public static function valoresInvalidosNoPainel(): array
    {
        return ['abc' => ['abc'], '1.234' => ['1.234'], 'R$' => ['R$']];
    }

    #[DataProvider('valoresInvalidosNoPainel')]
    public function test_preco_invalido_da_erro_no_campo_e_nao_grava(string $digitado): void
    {
        $product = $this->sellableProduct(9990);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['base_price_cents' => $digitado])
            ->call('save')
            ->assertHasFormErrors(['base_price_cents'])
            ->assertSee(ValidMoneyInput::MESSAGE);

        $this->assertSame(9990, $product->fresh()->base_price_cents);
    }

    /** @return array<string, array{int, string}> */
    public static function valoresGravados(): array
    {
        return [
            'R$ 1.234,00' => [123400, '1.234,00'],
            'R$ 1.000.000,00' => [100000000, '1.000.000,00'],
            'R$ 50,00' => [5000, '50,00'],
            'R$ 1.234,56' => [123456, '1.234,56'],
        ];
    }

    #[DataProvider('valoresGravados')]
    public function test_abrir_e_salvar_a_peca_sem_mexer_mantem_o_preco(int $centavos, string $exibido): void
    {
        $product = $this->sellableProduct($centavos);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertFormSet(['base_price_cents' => $exibido])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($centavos, $product->fresh()->base_price_cents);
    }

    #[DataProvider('valoresGravados')]
    public function test_abrir_e_salvar_o_tamanho_sem_mexer_mantem_o_acrescimo(int $centavos, string $exibido): void
    {
        $size = Size::factory()->create(['price_modifier_in_cents' => $centavos]);

        Livewire::test(ListSizes::class)
            ->mountTableAction('edit', $size)
            ->assertTableActionDataSet(['price_modifier_in_cents' => $exibido])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame($centavos, $size->fresh()->price_modifier_in_cents);
    }

    private function sellableProduct(int $basePriceCents): Product
    {
        $product = Product::factory()->materials(['pla'])->create(['base_price_cents' => $basePriceCents]);
        $product->categories()->attach(Category::factory()->create());
        $product->sizes()->attach(Size::factory()->create());
        $product->colors()->attach(Color::factory()->create());

        return $product;
    }
}
