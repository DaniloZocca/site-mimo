<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    public function definition(): array
    {
        $name = 'Material '.Str::lower(Str::random(6));

        return [
            'code' => Material::codeFromName($name),
            'short_name' => $name,
            'full_name' => $name.' (Teste)',
            'description' => 'Filamento de teste.',
            'is_active' => true,
            'sort_order' => 10,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
