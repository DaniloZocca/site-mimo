<?php

declare(strict_types=1);

namespace Tests\Feature\Stack;

use App\Console\Commands\CheckProductionConfigCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-42: o `mimoo:check-production` avisa (sem reprovar) quando o PHP está abaixo da versão que corrige a
 * PHP2Shell (CVE-2026-109618 e CVE-2026-109619): 8.3.36 no ramo 8.3 e 8.4.27 no ramo 8.4.
 */
class PhpVersionWarningTest extends CharacterizationTestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function versoes(): array
    {
        return [
            '8.3.35 avisa' => ['8.3.35', '8.3.36'],
            '8.3.6 avisa' => ['8.3.6', '8.3.36'],
            '8.3.36 não avisa' => ['8.3.36', null],
            '8.3.40 não avisa' => ['8.3.40', null],
            '8.4.26 avisa' => ['8.4.26', '8.4.27'],
            '8.4.0 avisa' => ['8.4.0', '8.4.27'],
            '8.4.27 não avisa' => ['8.4.27', null],
            '8.5.12 não avisa' => ['8.5.12', null],
        ];
    }

    #[DataProvider('versoes')]
    public function test_compara_com_a_versao_corrigida_do_ramo(string $version, ?string $patched): void
    {
        $warning = CheckProductionConfigCommand::phpVersionWarning($version);

        if ($patched === null) {
            $this->assertNull($warning);

            return;
        }

        $this->assertNotNull($warning);
        $this->assertStringStartsWith("PHP {$version} não tem a correção de segurança de 21/10/2026", $warning);
        $this->assertStringContainsString("Atualize para {$patched} ou mais novo", $warning);
    }

    public function test_aviso_de_versao_nao_reprova_o_comando(): void
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

        $command = $this->artisan('mimoo:check-production');
        $warning = CheckProductionConfigCommand::phpVersionWarning(PHP_VERSION);
        if ($warning !== null) {
            $command->expectsOutput($warning);
        }

        $command->expectsOutput('Configuração de produção ok.')->assertSuccessful();
    }
}
