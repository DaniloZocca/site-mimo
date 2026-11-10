<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPages;

use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-41, item 6: sem razão social e CNPJ o `mimoo:check-production` reprova (a Política de Privacidade e o
 * rodapé dos e-mails identificam a loja). A cidade é opcional.
 */
class CheckProductionStoreDataTest extends CharacterizationTestCase
{
    public function test_sem_razao_social_e_cnpj_reprova_citando_os_dois(): void
    {
        $this->configureProduction();
        config()->set('loja.razao_social', '');
        config()->set('loja.cnpj', '');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Dados da loja em branco (LOJA_RAZAO_SOCIAL, LOJA_CNPJ)')
            ->assertFailed();
    }

    public function test_so_sem_cnpj_reprova_citando_o_cnpj(): void
    {
        $this->configureProduction();
        config()->set('loja.cnpj', '');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('Dados da loja em branco (LOJA_CNPJ)')
            ->assertFailed();
    }

    public function test_com_razao_social_e_cnpj_passa_mesmo_sem_cidade(): void
    {
        $this->configureProduction();
        config()->set('loja.cidade_uf', '');

        $this->artisan('mimoo:check-production')
            ->doesntExpectOutputToContain('Dados da loja')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    public function test_config_le_os_dados_do_env_sem_espacos_nas_pontas_e_vazio_por_padrao(): void
    {
        $names = ['LOJA_RAZAO_SOCIAL', 'LOJA_CNPJ', 'LOJA_CIDADE_UF'];
        $original = [];
        foreach ($names as $name) {
            $original[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        }

        try {
            $this->setEnv('LOJA_RAZAO_SOCIAL', ' Exemplo Ltda ');
            $this->setEnv('LOJA_CNPJ', null);
            $this->setEnv('LOJA_CIDADE_UF', null);

            $this->assertSame(
                ['razao_social' => 'Exemplo Ltda', 'cnpj' => '', 'cidade_uf' => ''],
                require config_path('loja.php'),
            );
        } finally {
            foreach ($original as $name => [$env, $server, $putenv]) {
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
        }
    }

    private function configureProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://loja.exemplo.test');
        config()->set('cors.allowed_origins', ['https://loja.exemplo.test']);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('session.secure', true);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', null);
        config()->set('queue.failed.database', config('database.default'));
        config()->set('loja.razao_social', 'Exemplo Impressões Ltda');
        config()->set('loja.cnpj', '11.222.333/0001-81');
        config()->set('loja.cidade_uf', 'Campinas / SP');
        // M-50: produção exige credenciais do Mercado Pago, Melhor Envio fora do sandbox, e-mail de verdade e loja em https
        config()->set('services.shipping.melhor_envio.url', 'https://melhorenvio.com.br');
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'pedidos@loja.exemplo.test');
        config()->set('app.frontend_url', 'https://loja.exemplo.test');
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }
}
