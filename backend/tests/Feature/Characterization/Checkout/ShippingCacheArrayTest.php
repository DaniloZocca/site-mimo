<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\DTOs\ShippingOptionDTO;
use App\Services\ShippingCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-35: o cache não desserializa objetos PHP (`cache.serializable_classes = false`). A cotação de frete
 * guarda só arrays e a segunda chamada, lida do cache, devolve as mesmas opções da primeira.
 * Cache em banco, como em produção: o cache `array` dos outros testes não serializa e não pegaria a falha.
 */
#[Group('characterization')]
class ShippingCacheArrayTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
    }

    public function test_config_nao_desserializa_objetos(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));
    }

    public function test_cotacao_do_melhor_envio_lida_do_cache_e_igual_a_primeira(): void
    {
        config(['services.shipping.melhor_envio.token' => 'token-melhor-envio-teste']);
        Http::fake(['*/api/v2/me/shipment/calculate' => Http::response([
            ['id' => 1, 'name' => 'PAC', 'price' => '28.03', 'delivery_time' => 4, 'company' => ['name' => 'Correios', 'picture' => 'https://exemplo.com/correios.png']],
            ['id' => 2, 'name' => 'SEDEX', 'price' => '41.50', 'delivery_time' => 2, 'company' => ['name' => 'Correios', 'picture' => null]],
        ], 200)]);
        $service = app(ShippingCalculationService::class);

        $first = $service->calculate('01001000', []);
        $second = $service->calculate('01001000', []);

        Http::assertSentCount(1);
        $this->assertSame([2803, 4150], array_map(fn (ShippingOptionDTO $o): int => $o->priceInCents, $second));
        $this->assertSameOptions($first, $second);
        $this->assertCacheHoldsNoObject();
    }

    public function test_tabela_de_reserva_lida_do_cache_e_igual_a_primeira(): void
    {
        config(['services.shipping.melhor_envio.token' => '']);
        $service = app(ShippingCalculationService::class);

        $first = $service->calculate('20040002', []);
        $second = $service->calculate('20040002', []);

        $this->assertSame([2290, 3690], array_map(fn (ShippingOptionDTO $o): int => $o->priceInCents, $second));
        $this->assertTrue($second[0]->metadata['fallback']);
        $this->assertSameOptions($first, $second);
        $this->assertCacheHoldsNoObject();
    }

    public function test_checkout_acha_o_servico_escolhido_pelo_cache(): void
    {
        config(['services.shipping.melhor_envio.token' => '']);
        $service = app(ShippingCalculationService::class);
        $service->calculate('01001000', []);

        $found = $service->findOption('01001000', [], 'sedex');

        $this->assertInstanceOf(ShippingOptionDTO::class, $found['option']);
        $this->assertSame(2290, $found['option']->priceInCents);
        $this->assertSame('fallback', ShippingCalculationService::priceSource($found['option']));
    }

    /**
     * @param  array<int, ShippingOptionDTO>  $first
     * @param  array<int, ShippingOptionDTO>  $second
     */
    private function assertSameOptions(array $first, array $second): void
    {
        $this->assertContainsOnlyInstancesOf(ShippingOptionDTO::class, $second);
        $this->assertSame(
            array_map(fn (ShippingOptionDTO $o): array => $o->toArray(), $first),
            array_map(fn (ShippingOptionDTO $o): array => $o->toArray(), $second),
        );
    }

    private function assertCacheHoldsNoObject(): void
    {
        $row = DB::table('cache')->where('key', 'like', '%shipping_rate:v2:%')->sole();
        $this->assertIsArray(unserialize($row->value, ['allowed_classes' => false]));
        $this->assertStringNotContainsString('O:', $row->value);
    }
}
