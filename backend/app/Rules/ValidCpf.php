<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCpf implements ValidationRule
{
    /**
     * Validação algorítmica real dos dígitos verificadores de CPF (Receita Federal do Brasil).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('O CPF informado deve ser um texto válido.');

            return;
        }

        // 1. Remove qualquer formatação (pontos, traços, espaços)
        $cpf = preg_replace('/\D/', '', $value);

        // 2. Deve ter exatamente 11 dígitos numéricos
        if (strlen($cpf) !== 11) {
            $fail('O CPF informado deve conter exatamente 11 dígitos.');

            return;
        }

        // 3. Bloqueia sequências repetitivas conhecidas (ex: 000.000.000-00, 111.111.111-11)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            $fail('O CPF informado possui um formato inválido.');

            return;
        }

        // 4. Validação algorítmica do 1º Dígito Verificador (Módulo 11)
        $sum1 = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum1 += (int) $cpf[$i] * (10 - $i);
        }
        $remainder1 = ($sum1 * 10) % 11;
        $digit1 = ($remainder1 === 10 || $remainder1 === 11) ? 0 : $remainder1;

        if ($digit1 !== (int) $cpf[9]) {
            $fail('O CPF informado é inválido.');

            return;
        }

        // 5. Validação algorítmica do 2º Dígito Verificador (Módulo 11)
        $sum2 = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum2 += (int) $cpf[$i] * (11 - $i);
        }
        $remainder2 = ($sum2 * 10) % 11;
        $digit2 = ($remainder2 === 10 || $remainder2 === 11) ? 0 : $remainder2;

        if ($digit2 !== (int) $cpf[10]) {
            $fail('O CPF informado é inválido.');
        }
    }
}
