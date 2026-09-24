<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number', 32)->unique();
            $table->uuid('tracking_token')->unique();
            $table->string('customer_name', 150);
            $table->string('customer_email', 150);
            $table->string('customer_cpf', 14);
            $table->string('customer_phone', 30);
            $table->jsonb('shipping_address');
            $table->string('status', 32)->default(OrderStatus::PENDING_PAYMENT->value)->index();
            $table->integer('total_cents');
            $table->integer('shipping_cost_cents')->default(0);
            $table->string('carrier_tracking_code', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
