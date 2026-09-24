<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'title' => 'Peça '.Str::random(8),
            'description' => 'Peça impressa em 3D para testes.',
            'base_price_cents' => 5000,
            'weight_grams' => 120,
            'estimated_print_time_hours' => 8,
            'allowed_materials' => ['pla'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function price(int $cents): static
    {
        return $this->state(['base_price_cents' => $cents]);
    }

    /**
     * @param  array<int, string>  $materials
     */
    public function materials(array $materials): static
    {
        return $this->state(['allowed_materials' => $materials]);
    }
}
