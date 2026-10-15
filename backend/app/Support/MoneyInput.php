<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converte o valor digitado no painel ("5,50", "5.50", "1.234,56") em centavos inteiros e volta,
 * sem passar por float (regra 2 do CLAUDE.md).
 */
final class MoneyInput
{
    /** Maior valor que cabe nas colunas `integer` de centavos (R$ 21.474.836,47). */
    private const MAX_CENTS = 2147483647;

    /**
     * O último separador (vírgula ou ponto) seguido de 1 ou 2 dígitos marca os centavos; antes dele,
     * o outro separador pode aparecer como milhar, em grupos de 3. Devolve null quando o texto não é
     * um valor ou é ambíguo ("1.234", "5,555"), para o painel mostrar erro em vez de adivinhar.
     */
    public static function toCents(mixed $input): ?int
    {
        if (is_int($input)) {
            $input = (string) $input;
        }

        if (! is_string($input)) {
            return null;
        }

        $text = trim(str_replace("\u{00A0}", ' ', $input));
        $text = trim((string) preg_replace('/^R\$/u', '', $text));

        if (preg_match('/^(-?)(\d[\d.,]*)$/', $text, $match) !== 1) {
            return null;
        }

        [, $sign, $body] = $match;
        $integerPart = $body;
        $decimalSeparator = null;
        $decimals = '';

        if (preg_match('/^(.+)([.,])(\d{1,2})$/', $body, $decimalMatch) === 1) {
            [, $integerPart, $decimalSeparator, $decimals] = $decimalMatch;
        }

        if (ctype_digit($integerPart)) {
            $reais = $integerPart;
        } else {
            // Milhar só vale com centavos e com o outro separador: "1.234,56" sim; "1.234" e "1.234.56" não.
            if ($decimalSeparator === null
                || preg_match('/^\d{1,3}(?:([.,])\d{3})+$/', $integerPart, $thousandsMatch) !== 1
                || $thousandsMatch[1] === $decimalSeparator
                || str_contains($integerPart, $decimalSeparator)) {
                return null;
            }

            $reais = str_replace($thousandsMatch[1], '', $integerPart);
        }

        $reais = ltrim($reais, '0');

        if (strlen($reais) > 10) {
            return null;
        }

        $cents = ((int) $reais) * 100 + (int) str_pad($decimals, 2, '0');

        if ($cents > self::MAX_CENTS) {
            return null;
        }

        return $sign === '-' ? -$cents : $cents;
    }

    /** Sempre com duas casas ("1.234,00"), para o valor gravado nunca aparecer como "1.234". */
    public static function format(?int $cents): string
    {
        $cents ??= 0;
        $absolute = abs($cents);
        $reais = (string) intdiv($absolute, 100);
        $grouped = ltrim(strrev(chunk_split(strrev($reais), 3, '.')), '.');

        return ($cents < 0 ? '-' : '').$grouped.','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
