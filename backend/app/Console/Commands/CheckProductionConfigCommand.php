<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Teste de fumaça do deploy: falha (código 1) se a configuração carregada não é segura para produção.
 * Rode no servidor depois de `php artisan config:cache`. Não imprime valores de segredos.
 */
class CheckProductionConfigCommand extends Command
{
    /** Job disponível há mais que isso sem ser pego indica worker parado. */
    private const STALE_JOB_MINUTES = 5;

    protected $signature = 'mimoo:check-production';

    protected $description = 'Falha se a configuração não estiver pronta para produção (APP_ENV, APP_DEBUG, CORS, cookie de sessão, HTTPS, fila).';

    public function handle(): int
    {
        $problems = [];

        if (! app()->isProduction()) {
            $problems[] = 'APP_ENV não é "production".';
        }

        if (config('app.debug') === true) {
            $problems[] = 'APP_DEBUG está ligado: erros mostrariam código e configuração ao visitante. Use APP_DEBUG=false.';
        }

        $origins = (array) config('cors.allowed_origins', []);
        if (in_array('*', $origins, true) || in_array('*', (array) config('cors.allowed_origins_patterns', []), true)) {
            $problems[] = 'CORS aceita qualquer origem (*). Defina CORS_ALLOWED_ORIGINS com o domínio do frontend.';
        } elseif ($origins === [] || array_filter($origins, fn ($origin) => ! str_starts_with((string) $origin, 'https://')) !== []) {
            $problems[] = 'CORS tem origem sem https:// (ex.: localhost de desenvolvimento). Defina CORS_ALLOWED_ORIGINS com o domínio do frontend.';
        }

        if (config('session.secure') !== true) {
            $problems[] = 'Cookie de sessão sem Secure. Use SESSION_SECURE_COOKIE=true.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL não começa com https://.';
        }

        $connection = (string) config('queue.default');
        if (config("queue.connections.{$connection}.driver") === 'sync' || $connection === 'sync') {
            $problems[] = 'Fila sync: e-mails e avisos rodam dentro da requisição do cliente. Use QUEUE_CONNECTION=database com um worker rodando.';
        }

        // Avisos (não reprovam): sinais de worker parado e jobs que falharam
        foreach ($this->queueWarnings($connection) as $warning) {
            $this->warn($warning);
        }

        if ($problems !== []) {
            $this->error('Configuração NÃO está pronta para produção:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->info('Configuração de produção ok.');

        return self::SUCCESS;
    }

    /**
     * Fila database: job disponível e não reservado há mais de 5 min (worker parado?) e contagem de failed_jobs.
     * Só conta linhas: não lê o conteúdo dos jobs (podem ter dados do pedido).
     *
     * @return list<string>
     */
    private function queueWarnings(string $connection): array
    {
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return [];
        }

        $messages = [];

        try {
            $jobs = DB::connection(config("queue.connections.{$connection}.connection"))
                ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<', Carbon::now()->subMinutes(self::STALE_JOB_MINUTES)->getTimestamp())
                ->count();

            $messages[] = $jobs > 0
                ? "Há {$jobs} job(s) parado(s) há mais de ".self::STALE_JOB_MINUTES.' min na fila: o worker pode estar parado.'
                : 'Fila: nenhum job parado.';
        } catch (Throwable) {
            $messages[] = 'Não foi possível ler a tabela de jobs da fila (banco fora do ar ou migration faltando).';
        }

        try {
            $failed = DB::connection(config('queue.failed.database'))
                ->table((string) config('queue.failed.table', 'failed_jobs'))
                ->count();

            $messages[] = "Jobs que falharam (failed_jobs): {$failed}";
        } catch (Throwable) {
            $messages[] = 'Não foi possível ler a tabela failed_jobs.';
        }

        return $messages;
    }
}
