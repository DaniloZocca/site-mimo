<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Size;
use Illuminate\Database\Seeder;

class SizeSeeder extends Seeder
{
    public function run(): void
    {
        $sizes = [
            [
                'name' => 'Pequeno (8cm)',
                'price_modifier_in_cents' => 0,
            ],
            [
                'name' => 'Médio Padrão (12cm)',
                'price_modifier_in_cents' => 1500,
            ],
            [
                'name' => 'Grande Decorativo (16cm)',
                'price_modifier_in_cents' => 3200,
            ],
            [
                'name' => 'Maxi / Colecionador (22cm)',
                'price_modifier_in_cents' => 6500,
            ],
        ];

        foreach ($sizes as $size) {
            Size::firstOrCreate(
                ['name' => $size['name']],
                $size
            );
        }
    }
}
