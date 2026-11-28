<?php

declare(strict_types=1);

namespace App\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Código do material da cor (M-84): sempre texto em minúsculas, vindo da tabela `materials`.
 * Aceita o enum `MaterialType` antigo na gravação (seeders e testes antigos ainda passam o enum).
 *
 * @implements CastsAttributes<string|null, string|BackedEnum|null>
 */
class MaterialCode implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : strtolower(trim((string) $value));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtolower(trim($value instanceof BackedEnum ? (string) $value->value : (string) $value));
    }
}
