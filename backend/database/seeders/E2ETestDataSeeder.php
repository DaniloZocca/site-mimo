<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MaterialType;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Database\Seeder;

class E2ETestDataSeeder extends Seeder
{
    /**
     * Popula a base de dados com itens realistas para os testes de integração E2E.
     */
    public function run(): void
    {
        // 1. Categoria
        $category = Category::firstOrCreate(
            ['slug' => 'toys-articulados'],
            ['name' => 'Toys & Articulados']
        );

        // 2. Filamentos (Cores)
        $colorPurple = Color::firstOrCreate(
            ['name' => 'Roxo MIMOO'],
            [
                'hex_code'      => '#7C3AED',
                'material_type' => MaterialType::PLA,
                'in_stock'      => true,
            ]
        );

        $colorPink = Color::firstOrCreate(
            ['name' => 'Rosa Pastel'],
            [
                'hex_code'      => '#F472B6',
                'material_type' => MaterialType::PETG,
                'in_stock'      => true,
            ]
        );

        // 3. Variações de Tamanho
        $sizeStandard = Size::firstOrCreate(
            ['name' => 'Padrão'],
            [
                'price_modifier_in_cents' => 0,
            ]
        );

        $sizeCollector = Size::firstOrCreate(
            ['name' => 'Colecionador'],
            [
                'price_modifier_in_cents' => 2500, // + R$ 25,00
            ]
        );

        // 4. Produto Principal do Catálogo
        $product = Product::firstOrCreate(
            ['slug' => 'mascote-vaquinha-articulada-3d'],
            [
                'title'                      => 'Mascote Vaquinha Articulada 3D',
                'description'                => 'A adorável vaquinha mascote da MIMOO LAB com articulações móveis impressa em camadas de alta precisão.',
                'base_price_cents'           => 4990, // R$ 49,90
                'weight_grams'               => 120,
                'estimated_print_time_hours' => 4.5,
                'is_active'                  => true,
            ]
        );

        // Associa relacionamentos
        $product->categories()->syncWithoutDetaching([$category->id]);
        $product->colors()->syncWithoutDetaching([$colorPurple->id, $colorPink->id]);
        $product->sizes()->syncWithoutDetaching([$sizeStandard->id, $sizeCollector->id]);
    }
}
