<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotência do POST /api/checkout. O índice UNIQUE em idempotency_key é a garantia
     * contra pedido duplicado (duplo clique, retry de rede, requisições simultâneas).
     * Nullable: pedidos anteriores não têm chave (NULLs não conflitam no UNIQUE).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('request_fingerprint', 64)->nullable();
            $table->uuid('payment_idempotency_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_fingerprint', 'payment_idempotency_key']);
        });
    }
};
