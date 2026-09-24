<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabela Pivô: category_product
        Schema::create('category_product', function (Blueprint $table): void {
            $table->foreignUuid('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['category_id', 'product_id']);
            $table->index('product_id');
        });

        // 2. Tabela Pivô: color_product
        Schema::create('color_product', function (Blueprint $table): void {
            $table->foreignUuid('color_id')
                ->constrained('colors')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['color_id', 'product_id']);
            $table->index('product_id');
        });

        // 3. Tabela Pivô: product_size
        Schema::create('product_size', function (Blueprint $table): void {
            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignUuid('size_id')
                ->constrained('sizes')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_id', 'size_id']);
            $table->index('size_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_size');
        Schema::dropIfExists('color_product');
        Schema::dropIfExists('category_product');
    }
};
