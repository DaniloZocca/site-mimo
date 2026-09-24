<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'color_id' => Color::factory(),
            'size_id' => null,
            'product_title_snapshot' => 'Dragão Articulado',
            'color_name_snapshot' => 'Roxo MIMOO',
            'size_name_snapshot' => 'Tamanho Padrão',
            'material_name_snapshot' => 'PLA Premium (Atóxico e Biodegradável)',
            'unit_price_cents' => 5000,
            'quantity' => 1,
        ];
    }
}
