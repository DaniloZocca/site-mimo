<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Services\ShippingCalculationService;
use App\Support\Cents;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-40: o frete converte reais em centavos pelo conversor único (Cents::fromReais), sem float.
 * O resultado tem de ser idêntico ao da conversão antiga, (int) round((float) $preço * 100),
 * e o campo "price" da resposta continua o mesmo número.
 */
#[Group('characterization')]
class ShippingCentsConverterTest extends CharacterizationTestCase
{
    private const PACKAGE = [
        'height_cm' => 6,
        'width_cm' => 11,
        'length_cm' => 16,
        'weight_kg' => 0.3,
        'manufacturing_lead_days' => 2,
        'manufacturing_hours' => 5,
    ];

    /** Conversão de antes do M-40, usada só como referência. */
    private static function legacyCents(int|float|string $price): int
    {
        return (int) round((float) $price * 100);
    }

    /**
     * CEP de cada faixa da tabela de reserva e os preços (em reais) que ela tinha antes do M-40.
     *
     * @return array<string, array{string, float, float}>
     */
    public static function fallbackRegions(): array
    {
        return [
            'São Paulo' => ['01001000', 14.90, 22.90],
            'Sudeste' => ['20040002', 22.90, 36.90],
            'Sul' => ['80010000', 26.90, 44.90],
            'Centro-Oeste' => ['70040010', 31.90, 52.90],
            'Norte e Nordeste' => ['40020000', 38.90, 64.90],
        ];
    }

    #[DataProvider('fallbackRegions')]
    public function test_tabela_de_reserva_em_centavos_da_os_mesmos_valores(string $cep, float $pacReais, float $sedexReais): void
    {
        $options = app(ShippingCalculationService::class)->calculateFallbackShipping($cep, self::PACKAGE);

        foreach (['pac' => $pacReais, 'sedex' => $sedexReais] as $index => $reais) {
            $option = $options[$index === 'pac' ? 0 : 1]->toArray();

            $this->assertSame($index, $option['service_code']);
            $this->assertSame(self::legacyCents($reais), $option['price_in_cents']);
            $this->assertSame($reais, $option['price']);
            $this->assertSame('R$ '.number_format($reais, 2, ',', '.'), $option['price_formatted']);
        }
    }

    public function test_os_nove_precos_da_tabela_de_reserva(): void
    {
        $service = app(ShippingCalculationService::class);
        $cents = [];

        foreach (self::fallbackRegions() as [$cep]) {
            foreach ($service->calculateFallbackShipping($cep, self::PACKAGE) as $option) {
                $cents[] = $option->priceInCents;
            }
        }

        $cents = array_values(array_unique($cents));
        sort($cents);

        $this->assertSame([1490, 2290, 2690, 3190, 3690, 3890, 4490, 5290, 6490], $cents);
    }

    /**
     * Formatos de preço que a API do Melhor Envio devolve (texto com 2 casas; número por garantia).
     *
     * @return array<string, array{int|float|string}>
     */
    public static function melhorEnvioPrices(): array
    {
        return [
            'texto 18.37' => ['18.37'],
            'texto 31.02' => ['31.02'],
            'texto 0.57 (×100 em float = 56.99…)' => ['0.57'],
            'texto 1.15 (×100 em float = 114.99…)' => ['1.15'],
            'texto 28.03' => ['28.03'],
            'texto 0.01' => ['0.01'],
            'texto 15.00' => ['15.00'],
            'texto 9.9' => ['9.9'],
            'texto 100000.00' => ['100000.00'],
            'número 64.9' => [64.9],
            'número 15' => [15],
        ];
    }

    #[DataProvider('melhorEnvioPrices')]
    public function test_preco_do_melhor_envio_da_o_mesmo_resultado(int|float|string $price): void
    {
        config(['services.shipping.melhor_envio.token' => 'token-melhor-envio-teste']);
        Http::fake(['*/api/v2/me/shipment/calculate' => Http::response([
            ['id' => 1, 'name' => 'PAC', 'price' => $price, 'delivery_time' => 4, 'company' => ['name' => 'Correios', 'picture' => null]],
        ], 200)]);

        $options = app(ShippingCalculationService::class)->calculate('01001000', []);

        $this->assertCount(1, $options);
        $quoted = $options[0]->toArray();
        $this->assertFalse($quoted['metadata']['fallback'] ?? false);
        $this->assertSame(self::legacyCents($price), $quoted['price_in_cents']);
        $this->assertSame((float) $price, $quoted['price']);
        $this->assertSame('R$ '.number_format((float) $price, 2, ',', '.'), $quoted['price_formatted']);
    }

    /**
     * Todos os valores de R$ 0,01 a R$ 10.000,00, em texto (como a API manda) e em número:
     * o conversor único e a conversão antiga dão o mesmo centavo.
     */
    public function test_conversor_unico_bate_com_a_conversao_antiga_em_toda_a_faixa(): void
    {
        $divergences = [];

        for ($cents = 1; $cents <= 1_000_000; $cents++) {
            $text = intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            $legacy = self::legacyCents($text);

            if ($legacy !== $cents || Cents::fromReais($text) !== $cents || Cents::fromReais((float) $text) !== $cents) {
                $divergences[] = $text;
            }
        }

        $this->assertSame([], $divergences);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusablePrices(): array
    {
        return [
            'zero' => ['0.00'],
            'negativo' => ['-5.00'],
            'texto' => ['abc'],
            'ausente' => [null],
        ];
    }

    #[DataProvider('unusablePrices')]
    public function test_preco_zero_negativo_ou_invalido_continua_descartado(mixed $price): void
    {
        config(['services.shipping.melhor_envio.token' => 'token-melhor-envio-teste']);
        Http::fake(['*/api/v2/me/shipment/calculate' => Http::response([
            ['id' => 1, 'name' => 'PAC', 'price' => $price, 'delivery_time' => 4, 'company' => ['name' => 'Correios', 'picture' => null]],
        ], 200)]);

        $options = app(ShippingCalculationService::class)->calculate('01001000', []);

        // Sem opção válida, cai na tabela de reserva (igual a antes)
        $this->assertTrue($options[0]->metadata['fallback']);
        $this->assertSame(1490, $options[0]->priceInCents);
    }
}
