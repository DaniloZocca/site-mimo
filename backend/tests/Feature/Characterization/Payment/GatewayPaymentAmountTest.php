<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Payment;

use App\DTOs\GatewayPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Conversão de transaction_amount (reais) para centavos sem aritmética de ponto flutuante.
 */
#[Group('characterization')]
class GatewayPaymentAmountTest extends TestCase
{
    /**
     * @return array<string, array{int|float|string, int}>
     */
    public static function exactAmounts(): array
    {
        return [
            '49.9' => [49.9, 4990],
            '0.1 + 0.2' => [0.1 + 0.2, 30],
            '63.41' => [63.41, 6341],
            '0.57 (×100 em float = 56.99…)' => [0.57, 57],
            '1.15 (×100 em float = 114.99…)' => [1.15, 115],
            '64.9' => [64.9, 6490],
            '100.01' => [100.01, 10001],
            'inteiro 100' => [100, 10000],
            'float 100.0' => [100.0, 10000],
            'zero' => [0, 0],
            'string "100.01"' => ['100.01', 10001],
            'string "49.9"' => ['49.9', 4990],
            'grande 12345678.9' => [12345678.9, 1234567890],
        ];
    }

    #[DataProvider('exactAmounts')]
    public function test_converte_para_centavos_exatos(int|float|string $amount, int $expectedCents): void
    {
        $this->assertSame($expectedCents, GatewayPayment::amountToCents($amount));
    }

    /**
     * @return array<string, array{int|float|string|null}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'três casas 99.995' => [99.995],
            'string com três casas' => ['10.001'],
            'notação científica' => ['1e3'],
            'negativo string' => ['-1'],
            'negativo float' => [-1.5],
            'negativo int' => [-1],
            'vazio' => [''],
            'texto' => ['abc'],
            'nulo' => [null],
            'infinito' => [INF],
            'NaN' => [NAN],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_valor_sem_representacao_exata_em_centavos_devolve_null(int|float|string|null $amount): void
    {
        $this->assertNull(GatewayPayment::amountToCents($amount));
    }

    public function test_from_mercado_pago_normaliza_os_campos(): void
    {
        $payment = GatewayPayment::fromMercadoPago([
            'id' => 123,
            'status' => 'approved',
            'transaction_amount' => 63.41,
            'currency_id' => 'BRL',
            'external_reference' => 'MIMOO-ABC',
            'live_mode' => true,
        ]);

        $this->assertSame('123', $payment->id);
        $this->assertSame('approved', $payment->status);
        $this->assertSame(6341, $payment->amountCents);
        $this->assertSame('BRL', $payment->currencyId);
        $this->assertSame('MIMOO-ABC', $payment->externalReference);
        $this->assertTrue($payment->liveMode);
    }

    public function test_from_mercado_pago_usa_payment_id_da_criacao_e_nulos_quando_ausentes(): void
    {
        $payment = GatewayPayment::fromMercadoPago(['payment_id' => '777', 'status' => 'approved']);

        $this->assertSame('777', $payment->id);
        $this->assertNull($payment->amountCents);
        $this->assertNull($payment->currencyId);
        $this->assertNull($payment->externalReference);
        $this->assertNull($payment->liveMode);
    }
}
