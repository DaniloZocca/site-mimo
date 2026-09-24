<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Size>
 */
class SizeFactory extends Factory
{
    protected $model = Size::class;

    public function definition(): array
    {
        return [
            'name' => 'Tamanho '.Str::random(4),
            'price_modifier_in_cents' => 0,
        ];
    }

    public function modifier(int $cents): static
    {
        return $this->state(['price_modifier_in_cents' => $cents]);
    }
}
