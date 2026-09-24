<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resultado da criação do pagamento no gateway para cada pedido, incluindo o que o
     * checkout respondeu ao cliente (status HTTP e status do pedido naquele momento).
     * Permite repetir a MESMA resposta num reenvio com a mesma Idempotency-Key sem chamar o gateway.
     */
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('method', 30);
            $table->string('external_id', 100)->nullable();
            $table->string('status', 40);
            $table->string('status_detail', 100)->nullable();
            $table->text('qr_code')->nullable();
            $table->text('qr_code_base64')->nullable();
            $table->text('ticket_url')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->string('order_status', 32);
            $table->timestamps();

            $table->index('order_id');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
