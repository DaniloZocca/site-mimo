<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Support\DatabaseSettings;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concurrency\Support\ConcurrencyTestCase;

/**
 * M-45: o mimoo:check-production reprova o deploy se o MariaDB estiver com o comportamento padrão
 * (que falha sem dar erro) em vez do comportamento igual ao do Postgres.
 */
#[Group('concurrency')]
class CheckProductionDatabaseTest extends ConcurrencyTestCase
{
    public function test_banco_bem_configurado_nao_tem_problema_de_banco(): void
    {
        $this->artisan('mimoo:check-production')
            ->doesntExpectOutputToContain('Isolamento do banco')
            ->doesntExpectOutputToContain('Espera por trava')
            ->doesntExpectOutputToContain('FOUND_ROWS')
            ->doesntExpectOutputToContain('utf8mb4_bin')
            ->doesntExpectOutputToContain('a loja precisa de MariaDB');
    }

    public function test_reprova_isolamento_repeatable_read(): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Isolamento do banco é REPEATABLE-READ: precisa ser READ COMMITTED')
            ->assertFailed();
    }

    public function test_reprova_espera_por_trava_diferente_de_10_s(): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Espera por trava do banco é 50 s: precisa ser 10 s')
            ->assertFailed();
    }

    public function test_reprova_found_rows_desligado(): void
    {
        $name = (string) config('database.default');
        config(["database.connections.{$name}.options" => [PDO::MYSQL_ATTR_FOUND_ROWS => false]]);
        DB::purge($name);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('FOUND_ROWS desligado')
            ->assertFailed();
    }

    public function test_reprova_coluna_de_email_sem_utf8mb4_bin(): void
    {
        DB::statement('ALTER TABLE customers MODIFY email VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL');

        try {
            $this->artisan('mimoo:check-production')
                ->expectsOutputToContain('Colunas sem utf8mb4_bin (comparariam "A" igual a "a"): customers.email.')
                ->assertFailed();
        } finally {
            DB::statement('ALTER TABLE customers MODIFY email VARCHAR(150) CHARACTER SET utf8mb4 COLLATE '.DatabaseSettings::BINARY_COLLATION.' NOT NULL');
        }
    }
}
