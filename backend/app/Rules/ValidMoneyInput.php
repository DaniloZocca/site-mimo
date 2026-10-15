<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\MoneyInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Campo de dinheiro do painel: recusa o que MoneyInput não converte com segurança.
 */
class ValidMoneyInput implements ValidationRule
{
    public const MESSAGE = 'Valor inválido. Use vírgula ou ponto só para os centavos (ex.: 5,50). Para valores acima de mil, digite 1234,00 ou 1.234,00.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (MoneyInput::toCents($value) === null) {
            $fail(self::MESSAGE);
        }
    }
}
