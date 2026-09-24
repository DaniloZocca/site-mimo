<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Decoração',
                'slug' => 'decoracao',
            ],
            [
                'name' => 'Suportes & Geek',
                'slug' => 'suportes-geek',
            ],
            [
                'name' => 'Articulados & Toys',
                'slug' => 'articulados-toys',
            ],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
