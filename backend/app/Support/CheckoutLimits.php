<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limites do checkout e da cotação de frete (M-06).
 */
final class CheckoutLimits
{
    /**
     * Linhas diferentes na sacola (cada linha já vai até 50 unidades). Cada linha custa até 3 consultas
     * ao banco na validação: sem teto, 10.000 linhas prendiam o servidor por ~17 s.
     */
    public const MAX_ITEM_LINES = 50;

    /** Mesmo teto do formulário do cartão (`CreditCardPaymentStep.jsx`, `maxInstallments: 6`). */
    public const MAX_INSTALLMENTS = 6;

    public const ITEM_LINES_MESSAGE = 'A sacola aceita até '.self::MAX_ITEM_LINES.' itens diferentes por pedido. Para uma encomenda maior, divida em mais de um pedido ou fale com a gente.';

    public const INSTALLMENTS_MESSAGE = 'O parcelamento no cartão vai até '.self::MAX_INSTALLMENTS.'x.';

    /**
     * Com linhas demais, as regras de cada item (as que consultam o banco) nem são montadas: o `max` de
     * `items` já recusa o pedido, sem nenhuma consulta.
     *
     * Passadas como função, as regras só são montadas dentro do limite (a lista de materiais do
     * checkout é buscada no banco ao montar, M-84).
     *
     * @param  array<string, mixed>|\Closure(): array<string, mixed>  $itemRules  regras `items.*.…`
     * @return array<string, mixed>
     */
    public static function itemRulesFor(mixed $items, array|\Closure $itemRules): array
    {
        if (is_array($items) && count($items) > self::MAX_ITEM_LINES) {
            return [];
        }

        return $itemRules instanceof \Closure ? $itemRules() : $itemRules;
    }
}
