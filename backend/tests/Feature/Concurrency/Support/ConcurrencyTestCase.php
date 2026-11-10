<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * M-45: testes de concorrência no MariaDB com duas conexões de verdade.
 *
 * - Sem DatabaseTransactions: cada passo grava e commita no banco, como em produção (um teste dentro de
 *   transação esconderia travas, isolamento e duplicatas). As tabelas são limpas antes e depois de cada teste.
 * - A segunda conexão é outro processo PHP (bin/worker.php) que roda o código real da loja ao mesmo tempo.
 * - A ordem é forçada pela trava: o teste segura a linha do pedido, confere que o outro processo ficou
 *   esperando, solta e confere o banco.
 *
 * Só roda no MariaDB (job `backend (mariadb 11.8.9)` do CI); nos outros bancos é pulado.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    /** Tempo para o processo auxiliar subir e chegar na trava. */
    protected const SETTLE_SECONDS = 2.0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Testes de concorrência rodam só no MariaDB (M-45).');
        }

        Harness::configure($this->app['config']);
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mariadb') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanDatabase();
        }

        parent::tearDown();
    }

    /**
     * Sobe o processo auxiliar sem esperar por ele.
     *
     * @param  array<string, mixed>  $args
     */
    protected function startWorker(string $scenario, array $args = []): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Feature/Concurrency/bin/worker.php'), $scenario, (string) json_encode($args)],
            base_path(),
            ['APP_ENV' => 'testing'],
            null,
            60,
        );
        $process->start();

        return $process;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function runWorker(string $scenario, array $args = []): array
    {
        return $this->resultOf($this->startWorker($scenario, $args));
    }

    /**
     * Espera o processo terminar e devolve o JSON da última linha.
     *
     * @return array<string, mixed>
     */
    protected function resultOf(Process $process): array
    {
        $process->wait();

        $this->assertTrue(
            $process->isSuccessful(),
            "Processo auxiliar falhou (código {$process->getExitCode()}):\n".$process->getOutput()."\n".$process->getErrorOutput()
        );

        $lines = array_values(array_filter(explode(PHP_EOL, trim($process->getOutput()))));
        $result = json_decode((string) end($lines), true);
        $this->assertIsArray($result, 'Saída do processo auxiliar sem JSON: '.$process->getOutput());

        return $result;
    }

    /**
     * O processo continua rodando depois de SETTLE_SECONDS: está parado na trava que o teste segura.
     */
    protected function assertWaitingForLock(Process ...$processes): void
    {
        usleep((int) (self::SETTLE_SECONDS * 1_000_000));

        foreach ($processes as $process) {
            $this->assertTrue(
                $process->isRunning(),
                'O processo auxiliar devia estar esperando a trava, mas terminou: '.$process->getOutput().$process->getErrorOutput()
            );
        }
    }

    private function cleanDatabase(): void
    {
        $tables = array_map(
            fn (object $row): string => (string) $row->name,
            DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'migrations'")
        );

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
