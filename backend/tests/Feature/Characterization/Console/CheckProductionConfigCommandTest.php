<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * `mimoo:check-production` é o teste de fumaça do deploy (C3, A4, M3 do relatório de segurança).
 */
#[Group('characterization')]
class CheckProductionConfigCommandTest extends CharacterizationTestCase
{
    private const VARS = ['CORS_ALLOWED_ORIGINS', 'SESSION_SECURE_COOKIE', 'APP_ENV'];

    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $name) {
            $this->original[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => [$env, $server, $putenv]) {
            $this->setEnv($name, null);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($putenv !== false) {
                putenv("{$name}={$putenv}");
            }
        }

        parent::tearDown();
    }

    public function test_configuracao_de_producao_correta_passa(): void
    {
        $this->configureProduction();

        $this->artisan('mimoo:check-production')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    public function test_app_debug_ligado_em_producao_falha(): void
    {
        $this->configureProduction();
        config()->set('app.debug', true);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('APP_DEBUG está ligado')
            ->assertFailed();
    }

    public function test_ambiente_que_nao_e_producao_falha(): void
    {
        $this->configureProduction();
        $this->app->detectEnvironment(fn () => 'local');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('APP_ENV não é "production"')
            ->assertFailed();
    }

    public function test_cors_com_asterisco_falha(): void
    {
        $this->configureProduction();
        config()->set('cors.allowed_origins', ['https://loja.exemplo.test', '*']);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('CORS aceita qualquer origem')
            ->assertFailed();
    }

    public function test_cors_so_com_origens_de_desenvolvimento_falha(): void
    {
        $this->configureProduction();
        config()->set('cors.allowed_origins', ['http://localhost:5173', 'http://127.0.0.1:5173']);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('CORS tem origem sem https://')
            ->assertFailed();
    }

    public function test_cookie_de_sessao_sem_secure_falha(): void
    {
        $this->configureProduction();
        config()->set('session.secure', false);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Cookie de sessão sem Secure')
            ->assertFailed();
    }

    public function test_app_url_sem_https_falha(): void
    {
        $this->configureProduction();
        config()->set('app.url', 'http://loja.exemplo.test');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('APP_URL não começa com https://')
            ->assertFailed();
    }

    public function test_fila_sync_falha(): void
    {
        $this->configureProduction();
        config()->set('queue.default', 'sync');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Fila sync: e-mails e avisos rodam dentro da requisição do cliente')
            ->assertFailed();
    }

    public function test_fila_database_sem_job_parado_mostra_fila_ok_e_failed_jobs(): void
    {
        $this->configureProduction();
        $this->insertJob(minutesAgo: 2);
        $this->insertFailedJob();
        $this->insertFailedJob();

        $this->artisan('mimoo:check-production')
            ->expectsOutput('Fila: nenhum job parado.')
            ->expectsOutput('Jobs que falharam (failed_jobs): 2')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    public function test_job_parado_ha_mais_de_5_minutos_avisa_sem_reprovar(): void
    {
        $this->configureProduction();
        $this->insertJob(minutesAgo: 6);
        $this->insertJob(minutesAgo: 10);
        $this->insertJob(minutesAgo: 6, reserved: true);

        $this->artisan('mimoo:check-production')
            ->expectsOutput('Há 2 job(s) parado(s) há mais de 5 min na fila: o worker pode estar parado.')
            ->expectsOutput('Jobs que falharam (failed_jobs): 0')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    public function test_cors_padrao_nao_tem_asterisco_e_lista_so_as_origens_de_desenvolvimento(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', null);

        $this->assertSame(
            ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000'],
            $this->loadConfig('cors')['allowed_origins']
        );
    }

    public function test_cors_usa_as_origens_do_env(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', 'https://mimoo.exemplo.test, https://www.mimoo.exemplo.test,');

        $this->assertSame(
            ['https://mimoo.exemplo.test', 'https://www.mimoo.exemplo.test'],
            $this->loadConfig('cors')['allowed_origins']
        );
    }

    public function test_cookie_de_sessao_e_secure_por_padrao_em_producao(): void
    {
        $this->setEnv('SESSION_SECURE_COOKIE', null);

        $this->setEnv('APP_ENV', 'production');
        $this->assertTrue($this->loadConfig('session')['secure']);

        $this->setEnv('APP_ENV', 'local');
        $this->assertFalse($this->loadConfig('session')['secure']);
    }

    public function test_session_secure_cookie_do_env_tem_precedencia(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('SESSION_SECURE_COOKIE', 'false');

        $this->assertFalse($this->loadConfig('session')['secure']);
    }

    public function test_loja_e_api_em_hosts_diferentes_sem_session_domain_avisa_sem_reprovar(): void
    {
        $this->configureProduction();
        config()->set('app.url', 'https://api.mimoolab.com.br');
        config()->set('app.frontend_url', 'https://mimoolab.com.br');
        config()->set('session.domain', null);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Login de cliente: a loja e a API estão em endereços diferentes')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    public function test_session_domain_comum_a_loja_e_api_nao_avisa(): void
    {
        $this->configureProduction();
        config()->set('app.url', 'https://api.mimoolab.com.br');
        config()->set('app.frontend_url', 'https://mimoolab.com.br');
        config()->set('session.domain', '.mimoolab.com.br');

        $this->artisan('mimoo:check-production')
            ->doesntExpectOutputToContain('Login de cliente')
            ->assertSuccessful();
    }

    public function test_session_domain_que_nao_cobre_a_loja_avisa(): void
    {
        $this->configureProduction();
        config()->set('app.url', 'https://api.mimoolab.com.br');
        config()->set('app.frontend_url', 'https://loja-mimoo.vercel.app');
        config()->set('session.domain', '.mimoolab.com.br');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Login de cliente: a loja e a API estão em endereços diferentes')
            ->assertSuccessful();
    }

    private function configureProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://loja.exemplo.test');
        config()->set('cors.allowed_origins', ['https://loja.exemplo.test']);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('session.secure', true);
        // M-32: produção usa fila database (o phpunit.xml usa sync); jobs e failed_jobs no banco do teste
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', null);
        config()->set('queue.failed.database', config('database.default'));
        // M-50: produção exige credenciais do Mercado Pago, Melhor Envio fora do sandbox, e-mail de verdade e loja em https
        config()->set('services.shipping.melhor_envio.url', 'https://melhorenvio.com.br');
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'pedidos@loja.exemplo.test');
        config()->set('app.frontend_url', 'https://loja.exemplo.test');
    }

    private function insertJob(int $minutesAgo, bool $reserved = false): void
    {
        $at = now()->subMinutes($minutesAgo)->getTimestamp();
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reserved ? $at : null,
            'available_at' => $at,
            'created_at' => $at,
        ]);
    }

    private function insertFailedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);
    }

    private function loadConfig(string $name): array
    {
        return require config_path("{$name}.php");
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }
}
