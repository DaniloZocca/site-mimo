<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DatabaseSettings;
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

    /**
     * M-42: primeira versão de cada ramo com a correção da PHP2Shell (CVE-2026-109618 e CVE-2026-109619,
     * lançada em 21/10/2026). O composer.json já recusa as anteriores.
     */
    private const PATCHED_PHP_VERSIONS = ['8.3' => '8.3.36', '8.4' => '8.4.27'];

    protected $signature = 'mimoo:check-production';

    protected $description = 'Falha se a configuração não estiver pronta para produção (APP_ENV, APP_DEBUG, CORS, cookie de sessão, HTTPS, fila, dados da loja).';

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

        // M-41: a Política de Privacidade e o rodapé dos e-mails identificam a loja (LGPD, art. 9º, II)
        $missingStoreData = array_keys(array_filter([
            'LOJA_RAZAO_SOCIAL' => (string) config('loja.razao_social') === '',
            'LOJA_CNPJ' => (string) config('loja.cnpj') === '',
        ]));
        if ($missingStoreData !== []) {
            $problems[] = 'Dados da loja em branco ('.implode(', ', $missingStoreData).'): a Política de Privacidade e o rodapé dos e-mails precisam identificar a loja.';
        }

        // M-45: as três diferenças do MariaDB que falham sem dar erro (isolamento, linhas afetadas, collation)
        // e a espera por trava. Valem por sessão, então são conferidas na conexão que a loja usa.
        $problems = [...$problems, ...$this->databaseProblems()];

        // Avisos (não reprovam): PHP sem a correção de segurança, login de cliente com cookie, sinais de worker
        // parado e jobs que falharam
        $warnings = [
            ...array_filter([self::phpVersionWarning(PHP_VERSION)]),
            ...$this->accountCookieWarnings(),
            ...$this->queueWarnings($connection),
            ...$this->databaseWarnings(),
        ];
        foreach ($warnings as $warning) {
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
     * M-45: o banco de produção é MariaDB (Hostinger). Confere, na conexão padrão, o que faz o MariaDB se comportar
     * como o Postgres: versão, READ COMMITTED, FOUND_ROWS (UPDATE que regrava o mesmo valor conta a linha),
     * espera por trava de 10 s e utf8mb4_bin nas colunas de token e e-mail. Em outro banco não faz nada (ver aviso).
     *
     * @return list<string>
     */
    private function databaseProblems(): array
    {
        $db = DB::connection();

        if (! in_array($db->getDriverName(), ['mariadb', 'mysql'], true)) {
            return [];
        }

        $problems = [];

        try {
            $version = (string) $db->selectOne('SELECT VERSION() AS v')->v;
            if (! str_contains(strtolower($version), 'mariadb') || version_compare($version, DatabaseSettings::MIN_MARIADB_VERSION, '<')) {
                $problems[] = "Banco {$version}: a loja precisa de MariaDB ".DatabaseSettings::MIN_MARIADB_VERSION.' ou mais novo.';
            }

            $isolation = (string) $db->selectOne('SELECT @@transaction_isolation AS v')->v;
            if ($isolation !== DatabaseSettings::ISOLATION) {
                $problems[] = "Isolamento do banco é {$isolation}: precisa ser READ COMMITTED (conexão mariadb do config/database.php).";
            }

            $lockWait = (int) $db->selectOne('SELECT @@innodb_lock_wait_timeout AS v')->v;
            if ($lockWait !== DatabaseSettings::LOCK_WAIT_TIMEOUT_SECONDS) {
                $problems[] = "Espera por trava do banco é {$lockWait} s: precisa ser ".DatabaseSettings::LOCK_WAIT_TIMEOUT_SECONDS.' s (o Mercado Pago desiste do webhook em 22 s).';
            }

            // Regrava o mesmo valor numa linha (nada muda): com FOUND_ROWS conta 1, sem ele conta 0
            $id = $db->table('migrations')->min('id');
            if ($id !== null && $db->update('UPDATE migrations SET migration = migration WHERE id = ?', [$id]) !== 1) {
                $problems[] = 'FOUND_ROWS desligado: o banco conta só linhas alteradas e "0 linhas" viraria conflito falso na mudança de status.';
            }

            $wrong = [];
            foreach (DatabaseSettings::BINARY_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    $collation = $db->selectOne(
                        'SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$db->getTablePrefix().$table, $column]
                    )?->c;

                    if ($collation !== DatabaseSettings::BINARY_COLLATION) {
                        $wrong[] = "{$table}.{$column}";
                    }
                }
            }
            if ($wrong !== []) {
                $problems[] = 'Colunas sem utf8mb4_bin (comparariam "A" igual a "a"): '.implode(', ', $wrong).'. Rode php artisan migrate --force.';
            }
        } catch (Throwable $e) {
            $problems[] = 'Não foi possível conferir o banco ('.$e::class.'): banco fora do ar ou migrations faltando.';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function databaseWarnings(): array
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mariadb', 'mysql'], true)
            ? []
            : ["Banco {$driver}: a produção é MariaDB (M-45); as checagens do banco não rodaram."];
    }

    /**
     * M-42: avisa (não reprova) quando o PHP deste comando está abaixo da versão corrigida do ramo. Pacote de
     * distribuição (Ubuntu/Debian) pode trazer a correção sem mudar o número, e o PHP do site (FPM) pode ser
     * outro que o do terminal, por isso é aviso.
     */
    public static function phpVersionWarning(string $version): ?string
    {
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $patched = self::PATCHED_PHP_VERSIONS[$branch] ?? null;

        if ($patched === null || version_compare($version, $patched, '>=')) {
            return null;
        }

        return "PHP {$version} não tem a correção de segurança de 21/10/2026 (CVE-2026-109618 e CVE-2026-109619). "
            ."Atualize para {$patched} ou mais novo, no site e no terminal, e rode php artisan queue:restart. "
            .'Se a hospedagem diz que o pacote já tem a correção com outro número, confira no changelog dela.';
    }

    /**
     * Conta de cliente (M-26): com loja e API em hosts diferentes, o front só lê o cookie XSRF-TOKEN se
     * SESSION_DOMAIN for o domínio comum aos dois (ex.: .mimoolab.com.br); sem isso todo login dá 419.
     *
     * @return list<string>
     */
    private function accountCookieWarnings(): array
    {
        $apiHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $frontHost = strtolower((string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST));

        if ($apiHost === '' || $frontHost === '' || $apiHost === $frontHost) {
            return [];
        }

        $domain = ltrim(strtolower((string) config('session.domain')), '.');
        $covers = fn (string $host): bool => $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));

        if ($covers($apiHost) && $covers($frontHost)) {
            return [];
        }

        return ['Login de cliente: a loja e a API estão em endereços diferentes e SESSION_DOMAIN não é o domínio comum aos dois (ex.: SESSION_DOMAIN=.mimoolab.com.br). Sem isso, entrar e criar conta dão erro 419.'];
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
