<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-50: o `mimoo:check-production` reprova credenciais e endereços que, esquecidos no .env de produção, não dão
 * erro nenhum (pagamento sem token, webhook recusado, frete do sandbox, e-mail que não sai, links para o localhost).
 * A mensagem nomeia a variável e nunca mostra o valor.
 */
class CheckProductionServicesTest extends CharacterizationTestCase
{
    private const SECRET_LIKE = 'APP_USR-valor-que-nao-pode-aparecer';

    public function test_configuracao_completa_passa_sem_aviso_de_servico(): void
    {
        $this->configureProduction();

        $this->artisan('mimoo:check-production')
            ->doesntExpectOutputToContain('MERCADOPAGO')
            ->doesntExpectOutputToContain('MELHOR_ENVIO')
            ->doesntExpectOutputToContain('MAIL_')
            ->doesntExpectOutputToContain('FRONTEND_URL')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function configuracoesQueReprovam(): array
    {
        return [
            'token do Mercado Pago vazio (pagamento falharia)' => [
                ['services.mercadopago.access_token' => ''],
                'MERCADOPAGO_ACCESS_TOKEN vazio ou de exemplo: todo pagamento falharia',
            ],
            'token de exemplo TEST-000000 (pagamento falharia)' => [
                ['services.mercadopago.access_token' => 'TEST-0000000000000000-000000-abc'],
                'MERCADOPAGO_ACCESS_TOKEN vazio ou de exemplo',
            ],
            'token de teste do Mercado Pago' => [
                ['services.mercadopago.access_token' => 'TEST-1234567890-111111-abcdef'],
                'MERCADOPAGO_ACCESS_TOKEN é de teste',
            ],
            'segredo do webhook vazio' => [
                ['services.mercadopago.webhook_secret' => ''],
                'MERCADOPAGO_WEBHOOK_SECRET vazio',
            ],
            'Melhor Envio no sandbox' => [
                ['services.shipping.melhor_envio.url' => 'https://sandbox.melhorenvio.com.br'],
                'MELHOR_ENVIO_URL vazia ou do sandbox',
            ],
            'Melhor Envio sem endereço' => [
                ['services.shipping.melhor_envio.url' => ''],
                'MELHOR_ENVIO_URL vazia ou do sandbox',
            ],
            'e-mail só no log' => [
                ['mail.default' => 'log'],
                'MAIL_MAILER é "log": nenhum e-mail chegaria ao cliente',
            ],
            'e-mail em memória' => [
                ['mail.default' => 'array'],
                'MAIL_MAILER é "array"',
            ],
            'remetente de exemplo' => [
                ['mail.from.address' => 'hello@example.com'],
                'MAIL_FROM_ADDRESS vazio ou de exemplo',
            ],
            'remetente vazio' => [
                ['mail.from.address' => ''],
                'MAIL_FROM_ADDRESS vazio ou de exemplo',
            ],
            'endereço da loja de desenvolvimento' => [
                ['app.frontend_url' => 'http://localhost:5173'],
                'FRONTEND_URL não começa com https://',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('configuracoesQueReprovam')]
    public function test_reprova(array $override, string $message): void
    {
        $this->configureProduction();
        config()->set($override);

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain($message)
            ->assertFailed();
    }

    public function test_mensagens_nunca_mostram_o_valor_das_credenciais(): void
    {
        $this->configureProduction();
        config()->set('services.mercadopago.access_token', 'TEST-'.self::SECRET_LIKE);
        config()->set('services.shipping.melhor_envio.url', 'https://sandbox.melhorenvio.com.br/'.self::SECRET_LIKE);
        config()->set('app.frontend_url', 'http://'.self::SECRET_LIKE);

        $this->artisan('mimoo:check-production')
            ->doesntExpectOutputToContain(self::SECRET_LIKE)
            ->assertFailed();
    }

    public function test_token_do_melhor_envio_vazio_so_avisa(): void
    {
        $this->configureProduction();
        config()->set('services.shipping.melhor_envio.token', '');

        $this->artisan('mimoo:check-production')
            ->expectsOutputToContain('MELHOR_ENVIO_TOKEN vazio: o frete usa a tabela de reserva')
            ->expectsOutput('Configuração de produção ok.')
            ->assertSuccessful();
    }

    private function configureProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://api.loja.exemplo.test');
        config()->set('app.frontend_url', 'https://loja.exemplo.test');
        config()->set('cors.allowed_origins', ['https://loja.exemplo.test']);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('session.secure', true);
        config()->set('session.domain', '.loja.exemplo.test');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', null);
        config()->set('queue.failed.database', config('database.default'));
        config()->set('loja.razao_social', 'Exemplo Impressões Ltda');
        config()->set('loja.cnpj', '11.222.333/0001-81');
        config()->set('services.mercadopago.access_token', 'APP_USR-1234567890-111111-producao');
        config()->set('services.mercadopago.webhook_secret', 'segredo-do-webhook');
        config()->set('services.shipping.melhor_envio.url', 'https://melhorenvio.com.br');
        config()->set('services.shipping.melhor_envio.token', 'token-do-melhor-envio');
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'pedidos@loja.exemplo.test');
    }
}
