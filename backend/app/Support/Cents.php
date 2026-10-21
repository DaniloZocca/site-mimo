<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Conversor único de reais para centavos inteiros, sem aritmética de ponto flutuante (regra 2 do
 * CLAUDE.md). Usado pelo painel (MoneyInput, depois de normalizar o texto digitado), pelo valor do
 * Mercado Pago (GatewayPayment) e pelo frete (ShippingCalculationService).
 */
final class Cents
{
    /**
     * Converte um valor em reais com ponto decimal ("49.9", 49.9, 100) em centavos.
     * O float vira texto decimal (15 dígitos significativos, o que remove o ruído binário:
     * 0.1 + 0.2 → "0.3") e o texto é convertido com aritmética inteira. Valores com mais de 2 casas
     * decimais, negativos, em notação científica, vazios ou grandes demais devolvem null (não há
     * valor exato em centavos).
     */
    public static function fromReais(int|float|string|null $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        if (is_int($amount)) {
            return $amount >= 0 && $amount <= intdiv(PHP_INT_MAX, 100) ? $amount * 100 : null;
        }

        if (is_float($amount)) {
            if (! is_finite($amount)) {
                return null;
            }
            $amount = sprintf('%.15G', $amount);
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m) !== 1) {
            return null;
        }

        // Limite de inteiro: acima disso não há valor exato em centavos
        if (strlen(ltrim($m[1], '0')) > 16) {
            return null;
        }

        $reais = (int) $m[1];
        $centavos = (int) str_pad($m[2] ?? '', 2, '0');

        return $reais * 100 + $centavos;
    }
}
