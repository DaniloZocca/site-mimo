<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classificação dos registros de produção:
     * - is_internal: registro de uso interno (painel), nunca exibido na timeline pública de rastreio.
     * - code/reference: tipo do evento (ex.: payment_approved, payment_review) e o identificador
     *   externo a que se refere (ex.: id do pagamento no Mercado Pago). Base da dedupe e dos filtros.
     * Registros anteriores ficam com is_internal=false e code/reference nulos (sem backfill).
     */
    public function up(): void
    {
        Schema::table('production_logs', function (Blueprint $table): void {
            $table->boolean('is_internal')->default(false);
            $table->string('code', 40)->nullable()->index();
            $table->string('reference', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('production_logs', function (Blueprint $table): void {
            $table->dropIndex(['code']);
            $table->dropIndex(['reference']);
            $table->dropColumn(['is_internal', 'code', 'reference']);
        });
    }
};
