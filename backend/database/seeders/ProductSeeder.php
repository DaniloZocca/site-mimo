<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $decoracao = Category::where('slug', 'decoracao')->first();
        $geek = Category::where('slug', 'suportes-geek')->first();
        $articulados = Category::where('slug', 'articulados-toys')->first();

        $allColors = Color::all();
        $allSizes = Size::all();

        $products = [
            [
                'title' => 'Mascote Vaquinha Articulada 3D',
                'slug' => 'mascote-vaquinha-articulada-3d',
                'description' => 'A adorável vaquinha mascote da MIMOO LAB com articulações móveis nas patinhas e cabeça, impressa em camadas ultrafinas de PLA biodegradável de altíssima precisão.',
                'base_price_cents' => 4990, // R$ 49,90
                'weight_grams' => 120,
                'estimated_print_time_hours' => 4.5,
                'is_active' => true,
                'category_id' => $articulados?->id,
                'images' => [
                    ['image_path' => 'https://images.unsplash.com/photo-1563089145-599997674d42?auto=format&fit=crop&w=800&q=80', 'alt_text' => 'Mascote Vaquinha 3D Frontal', 'display_order' => 1],
                ],
            ],
            [
                'title' => 'Vaso Geométrico Minimalista Origami',
                'slug' => 'vaso-geometrico-minimalista-origami',
                'description' => 'Vaso decorativo moderno com facetas inspiradas na arte japonesa do origami. Perfeito para suculentas, flores secas ou como peça central de mesa.',
                'base_price_cents' => 3990, // R$ 39,90
                'weight_grams' => 150,
                'estimated_print_time_hours' => 5.0,
                'is_active' => true,
                'category_id' => $decoracao?->id,
                'images' => [
                    ['image_path' => 'https://images.unsplash.com/photo-1578749556568-bc2c40e68b61?auto=format&fit=crop&w=800&q=80', 'alt_text' => 'Vaso Geométrico Origami', 'display_order' => 1],
                ],
            ],
            [
                'title' => 'Suporte Gamer de Headset Geek',
                'slug' => 'suporte-gamer-headset-geek',
                'description' => 'Suporte ergonômico e resistente para fones de ouvido e headsets gamer. Base pesada e estável com canaletas para organização de cabos.',
                'base_price_cents' => 5990, // R$ 59,90
                'weight_grams' => 220,
                'estimated_print_time_hours' => 6.5,
                'is_active' => true,
                'category_id' => $geek?->id,
                'images' => [
                    ['image_path' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?auto=format&fit=crop&w=800&q=80', 'alt_text' => 'Suporte Headset Geek', 'display_order' => 1],
                ],
            ],
            [
                'title' => 'Dragão Articulado Cristalino Fidget Toy',
                'slug' => 'dragao-articulado-cristalino-fidget-toy',
                'description' => 'Criatura mítica impressa em peça única com mais de 30 vértebras móveis flexíveis. Excelente para foco, relaxamento e decoração gamer.',
                'base_price_cents' => 6990, // R$ 69,90
                'weight_grams' => 180,
                'estimated_print_time_hours' => 7.0,
                'is_active' => true,
                'category_id' => $articulados?->id,
                'images' => [
                    ['image_path' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=80', 'alt_text' => 'Dragão Articulado Cristalino', 'display_order' => 1],
                ],
            ],
            [
                'title' => 'Luminária Lua Lunar Texturizada',
                'slug' => 'luminaria-lua-lunar-texturizada',
                'description' => 'Globo lunar com relevos topográficos detalhados da superfície lunar, impresso com técnica de litofania para criar uma luz ambiente suave.',
                'base_price_cents' => 8990, // R$ 89,90
                'weight_grams' => 260,
                'estimated_print_time_hours' => 10.0,
                'is_active' => true,
                'category_id' => $decoracao?->id,
                'images' => [
                    ['image_path' => 'https://images.unsplash.com/photo-1532767153582-b1a0e5145009?auto=format&fit=crop&w=800&q=80', 'alt_text' => 'Luminária Lua Texturizada', 'display_order' => 1],
                ],
            ],
            [
                'title' => 'Suporte para Celular & Tablet Vaquinha',
                'slug' => 'suporte-celular-tablet-vaquinha',
                'description' => 'Suporte fofo e resistente em formato da mascote vaquinha para celulares e tablets em ângulo ideal de visualização para chamadas e vídeos.',
                'base_price_cents' => 3490, // R$ 34,90
                'weight_grams' => 95,
                'estimated_print_time_hours' => 3.5,
                'is_active' => true,
                'category_id' => $geek?->id,
                'images' => [
                    ['image_path' => 'https://images.unsplash.com/photo-1586105251261-72a756497a11?auto=format&fit=crop&w=800&q=80', 'alt_text' => 'Suporte Celular Vaquinha', 'display_order' => 1],
                ],
            ],
        ];

        foreach ($products as $prodData) {
            $images = $prodData['images'];
            $categoryId = $prodData['category_id'];
            unset($prodData['images'], $prodData['category_id']);

            /** @var Product $product */
            $product = Product::updateOrCreate(
                ['slug' => $prodData['slug']],
                $prodData
            );

            if ($categoryId) {
                $product->categories()->syncWithoutDetaching([$categoryId]);
            }

            if ($allColors->isNotEmpty()) {
                $product->colors()->syncWithoutDetaching($allColors->pluck('id')->toArray());
            }

            if ($allSizes->isNotEmpty()) {
                $product->sizes()->syncWithoutDetaching($allSizes->pluck('id')->toArray());
            }

            foreach ($images as $img) {
                $product->images()->updateOrCreate(
                    ['display_order' => $img['display_order']],
                    [
                        'image_path' => $img['image_path'],
                        'alt_text' => $img['alt_text'],
                    ]
                );
            }
        }
    }
}
