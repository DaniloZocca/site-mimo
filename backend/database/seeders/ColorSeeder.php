<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MaterialType;
use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    public function run(): void
    {
        $colors = [
            ['name' => 'Lilás MIMOO', 'hex_code' => '#A855F7', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
            ['name' => 'Rosa Algodão', 'hex_code' => '#FF85B2', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
            ['name' => 'Verde Menta', 'hex_code' => '#6EE7B7', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
            ['name' => 'Amarelo Baunilha', 'hex_code' => '#FDE047', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
            ['name' => 'Azul Bebê', 'hex_code' => '#7DD3FC', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
            ['name' => 'Seda Dourada', 'hex_code' => '#F59E0B', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
            ['name' => 'Branco Neve', 'hex_code' => '#F8FAFC', 'material_type' => MaterialType::PETG->value, 'in_stock' => true],
            ['name' => 'Preto Ônix Fosco', 'hex_code' => '#1E293B', 'material_type' => MaterialType::PLA->value, 'in_stock' => true],
        ];

        foreach ($colors as $color) {
            Color::firstOrCreate(
                ['name' => $color['name']],
                $color
            );
        }
    }
}
