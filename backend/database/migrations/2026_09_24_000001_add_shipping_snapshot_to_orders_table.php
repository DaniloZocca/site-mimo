<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot do frete cobrado (recalculado no servidor no checkout).
     * Colunas nullable: pedidos anteriores a esta migration não têm o snapshot.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_service_code', 30)->nullable();
            $table->string('shipping_service_name', 100)->nullable();
            $table->string('shipping_carrier_name', 100)->nullable();
            $table->integer('shipping_delivery_days')->nullable();
            $table->string('shipping_price_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_service_code',
                'shipping_service_name',
                'shipping_carrier_name',
                'shipping_delivery_days',
                'shipping_price_source',
            ]);
        });
    }
};
