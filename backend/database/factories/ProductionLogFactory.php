<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionLog>
 */
class ProductionLogFactory extends Factory
{
    protected $model = ProductionLog::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'previous_status' => 'checkout',
            'new_status' => OrderStatus::PENDING_PAYMENT->value,
            'notes' => 'Log criado pela factory de teste.',
            'created_at' => now(),
        ];
    }
}
