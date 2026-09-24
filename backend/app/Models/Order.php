<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuids;

    /**
     * Campos protegidos contra Mass Assignment: status, total_cents e paid_at
     * são alterados exclusivamente por métodos de negócio e eventos de checkout/pagamento.
     */
    protected $fillable = [
        'order_number',
        'tracking_token',
        'customer_name',
        'customer_email',
        'customer_cpf',
        'customer_phone',
        'shipping_address',
        'shipping_cost_cents',
        'carrier_tracking_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'              => OrderStatus::class,
            'shipping_address'    => 'array',
            'total_cents'         => 'integer',
            'shipping_cost_cents' => 'integer',
            'shipping_delivery_days' => 'integer',
            'paid_at'             => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class)->orderBy('created_at', 'desc');
    }
}
