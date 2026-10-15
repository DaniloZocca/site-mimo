<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M-26: com o guard `customer`, a sessão em banco grava em `sessions.user_id` o UUID do cliente
 * (o handler usa o guard da requisição). A coluna era bigint (só admins de `users`) e o Postgres
 * recusava o UUID. Passa a texto, que guarda os dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->string('user_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sessões são descartáveis: as de cliente (UUID) não cabem em bigint e todos voltam a entrar.
        DB::table('sessions')->delete();

        // Recria a coluna como era (o Postgres não converte texto em bigint sozinho).
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->index();
        });
    }
};
