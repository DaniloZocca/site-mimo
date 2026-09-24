<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use App\Models\Size;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Casos-ouro de cálculo do total (OrderCreationService).
 * Regra observada: unit = base + modificador do tamanho; subtotal = Σ unit × qtd;
 * PIX: desconto = (int) round(subtotal × 0.10) — só nas peças, arredondamento "meio para cima";
 * total = subtotal − desconto + frete recalculado no servidor (1490 = PAC do fallback SP nos testes).
 */
#[Group('characterization')]
class CheckoutPricingTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    /**
     * @return array{items: array<int, array<string, mixed>>}
     */
    private function goldenCart(): array
    {
        $a = Product::factory()->price(5000)->create();
        $b = Product::factory()->price(3990)->create();
        $c = Product::factory()->price(1234)->create();
        $color = Color::factory()->create();
        foreach ([$a, $b, $c] as $p) {
            $p->colors()->attach($color->id);
        }
        $big = Size::factory()->modifier(1500)->create(['name' => 'Grande (18cm)']);
        $zero = Size::factory()->modifier(0)->create(['name' => 'Médio (12cm)']);
        $b->sizes()->attach($big->id);
        $c->sizes()->attach($zero->id);

        return ['items' => [
            $this->itemPayload($a, $color, null, 2),   // 5000 × 2 = 10000
            $this->itemPayload($b, $color, $big, 3),   // (3990 + 1500) × 3 = 16470
            $this->itemPayload($c, $color, $zero, 1),  // (1234 + 0) × 1 = 1234
        ]];                                             // subtotal = 27704
    }

    public function test_caso_ouro_cartao_sem_desconto(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload($this->goldenCart()['items'], 'credit_card'))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 29194)          // 27704 + 1490
            ->assertJsonPath('total_formatted', 'R$ 291,94');

        $this->assertSame(292, (int) round($this->mpHttp->lastPayload()['transaction_amount']));
        $this->assertEqualsWithDelta(291.94, $this->mpHttp->lastPayload()['transaction_amount'], 0.0001);
    }

    public function test_caso_ouro_pix_desconto_so_nas_pecas(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        // desconto = round(2770.4) = 2770 → 27704 − 2770 + 1490 = 26424 (frete sem desconto)
        $this->postJson('/api/checkout', $this->checkoutPayload($this->goldenCart()['items'], 'pix'))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 26424)
            ->assertJsonPath('total_formatted', 'R$ 264,24');

        $this->assertStringContainsString('(Desconto PIX 10% aplicado: R$ 27,70)', Order::sole()->productionLogs()->sole()->notes);
    }

    public function test_unit_price_de_cada_item_inclui_o_modificador(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($this->goldenCart()['items'], 'pix'))->assertStatus(201);

        $units = Order::sole()->items()->orderBy('unit_price_cents')->pluck('unit_price_cents', 'quantity')->all();
        $this->assertSame([1 => 1234, 2 => 5000, 3 => 5490], $units);
    }

    /**
     * Arredondamento do desconto PIX para subtotais ímpares ($expectedTotal = peças − desconto, sem o frete).
     *
     * @return array<string, array{int, int}>
     */
    public static function pixRounding(): array
    {
        return [
            '4995 → desconto 499,5 → 500' => [4995, 4495],
            '4985 → desconto 498,5 → 499' => [4985, 4486],
            '4975 → desconto 497,5 → 498' => [4975, 4477],
            '1005 → desconto 100,5 → 101' => [1005, 904],
            '15 → desconto 1,5 → 2' => [15, 13],
            '5 → desconto 0,5 → 1' => [5, 4],
            '1 → desconto 0,1 → 0' => [1, 1],
            '9 → desconto 0,9 → 1' => [9, 8],
            '99995 → desconto 9999,5 → 10000' => [99995, 89995],
            '12345 → desconto 1234,5 → 1235' => [12345, 11110],
        ];
    }

    #[DataProvider('pixRounding')]
    public function test_arredondamento_do_desconto_pix(int $priceCents, int $expectedTotal): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => $priceCents]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'pix'))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', $expectedTotal + self::SERVER_PAC_CENTS);
    }

    public function test_pix_desconto_calculado_sobre_o_subtotal_somado_e_nao_por_item(): void
    {
        // Dois itens de 15 centavos: round(30 × 0,10) = 3 (por item seria 2 + 2 = 4).
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 15]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color, null, 2)], 'pix'))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 27 + self::SERVER_PAC_CENTS);
    }

    public function test_mesmo_produto_em_duas_linhas_gera_dois_itens(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 1000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload([
            $this->itemPayload($product, $color, null, 1),
            $this->itemPayload($product, $color, null, 2),
        ], 'credit_card'))->assertStatus(201)->assertJsonPath('total_cents', 3000 + self::SERVER_PAC_CENTS);

        $this->assertSame(2, Order::sole()->items()->count());
    }
}
