<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->foreignUuid('color_id')
                ->nullable()
                ->constrained('colors')
                ->nullOnDelete();
            $table->foreignUuid('size_id')
                ->nullable()
                ->constrained('sizes')
                ->nullOnDelete();

            $table->string('product_title_snapshot', 255);
            $table->string('color_name_snapshot', 100);
            $table->string('size_name_snapshot', 100)->nullable();
            $table->integer('unit_price_cents');
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
