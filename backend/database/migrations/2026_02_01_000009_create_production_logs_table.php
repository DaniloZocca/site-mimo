<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->string('previous_status', 32);
            $table->string('new_status', 32);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_logs');
    }
};
