<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialType;
use App\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    protected $model = Color::class;

    public function definition(): array
    {
        return [
            'name' => 'Cor '.Str::random(5),
            'hex_code' => '#7C3AED',
            'material_type' => MaterialType::PLA,
            'in_stock' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['in_stock' => false]);
    }

    public function material(MaterialType $material): static
    {
        return $this->state(['material_type' => $material]);
    }
}
