<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory de pedido. Os campos protegidos (status, total_cents, paid_at) são
 * preenchidos porque as factories do Laravel criam o model sem guarda.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => 'MIMOO-'.strtoupper(Str::random(8)),
            'tracking_token' => (string) Str::uuid(),
            'customer_name' => 'Fernanda Souza Lima',
            'customer_email' => 'fernanda@example.com',
            'customer_cpf' => '52998224725',
            'customer_phone' => '11988887777',
            'shipping_address' => [
                'zip_code' => '01001000',
                'street' => 'Praça da Sé',
                'number' => '100',
                'complement' => 'Apto 12',
                'neighborhood' => 'Sé',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
            'status' => OrderStatus::PENDING_PAYMENT,
            'total_cents' => 10000,
            'shipping_cost_cents' => 1500,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
