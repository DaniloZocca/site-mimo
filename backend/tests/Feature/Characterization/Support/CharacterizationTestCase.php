<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\TestCase;

/**
 * Base dos testes de caracterização (e dos gêmeos em KnownBugs).
 *
 * Garantias:
 * - Banco SEMPRE SQLite em memória, forçado depois do boot da aplicação. Uma trava em
 *   beforeRefreshingDatabase() aborta o teste antes de qualquer migration se a conexão
 *   ativa não for sqlite/:memory: (nunca pode tocar o Postgres de desenvolvimento).
 * - Nenhuma chamada de rede: Http::preventStrayRequests() e o SDK do Mercado Pago recebe
 *   um cliente HTTP falso (FakeMercadoPagoHttpClient) que falha em requisição não programada.
 * - Config do Mercado Pago fixada aqui; o .env da máquina não influencia.
 * - Tempo congelado em 2026-09-23 12:00:00 no fuso America/Sao_Paulo; idioma pt_BR.
 * - Queue::fake() e Mail::fake(): listeners enfileirados e jobs não executam; os testes
 *   que caracterizam listeners os executam explicitamente.
 */
abstract class CharacterizationTestCase extends TestCase
{
    use RefreshDatabase;

    public const WEBHOOK_SECRET = 'segredo-de-teste-characterization';

    /** Token "real" (não cai no modo simulado do PIX, que só ativa com vazio ou TEST-000000). */
    public const ACCESS_TOKEN = 'APP_USR-characterization-token';

    public const FROZEN_NOW = '2026-09-23 12:00:00';

    protected FakeMercadoPagoHttpClient $mpHttp;

    /** Subclasses que precisam executar listeners/jobs de verdade definem false. */
    protected bool $fakeQueue = true;

    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];

        // Banco: SQLite em memória, ignorando DB_CONNECTION/DB_URL do .env.
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.url', null);
        $config->set('database.connections.sqlite.database', ':memory:');
        $config->set('database.connections.sqlite.foreign_key_constraints', true);

        // Serviços externos com valores fixos de teste.
        $config->set('services.mercadopago.access_token', self::ACCESS_TOKEN);
        $config->set('services.mercadopago.public_key', 'APP_USR-public-characterization');
        $config->set('services.mercadopago.webhook_secret', self::WEBHOOK_SECRET);
        $config->set('services.shipping.melhor_envio.token', '');

        // Fuso e idioma iguais ao .env de desenvolvimento (America/Sao_Paulo, pt_BR), fixados aqui
        // para o resultado não depender da máquina. date_default_timezone_set é necessário porque
        // o LoadConfiguration já aplicou o fuso do .env durante o boot.
        $config->set('app.timezone', 'America/Sao_Paulo');
        date_default_timezone_set('America/Sao_Paulo');
        $config->set('app.locale', 'pt_BR');
        $config->set('app.fallback_locale', 'pt_BR');

        // Infra determinística.
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('app.url', 'http://localhost');
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('mail.default', 'array');
        $config->set('session.driver', 'array');
        $config->set('logging.default', 'null');

        return $app;
    }

    /**
     * Trava de segurança: roda antes de qualquer migration do RefreshDatabase.
     */
    protected function beforeRefreshingDatabase()
    {
        // Só lê a configuração: nada aqui abre conexão antes de a trava decidir.
        $default = config('database.default');
        $driver = config("database.connections.{$default}.driver");
        $database = config("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $driver !== 'sqlite' || $database !== ':memory:') {
            $this->fail(sprintf(
                'TRAVA DE SEGURANÇA: testes de caracterização só rodam em SQLite :memory:. '
                .'Conexão configurada: default=%s driver=%s database=%s. Nenhuma migration foi executada.',
                (string) $default,
                (string) $driver,
                (string) $database
            ));
        }

        if (! extension_loaded('pdo_sqlite')) {
            $this->fail(
                'Os testes de caracterização precisam da extensão pdo_sqlite do PHP. '
                .'Rode `php --ini`, abra o php.ini indicado e descomente as linhas `extension=pdo_sqlite` e `extension=sqlite3`. '
                .'Nenhuma migration foi executada.'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        if ($this->fakeQueue) {
            Queue::fake();
        }
        Mail::fake();

        $this->mpHttp = new FakeMercadoPagoHttpClient;
        MercadoPagoConfig::setHttpClient($this->mpHttp);

        $this->travelTo(self::FROZEN_NOW);
    }

    protected function tearDown(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);

        parent::tearDown();
    }

    /**
     * Configura o segredo do webhook (string vazia = segredo ausente).
     */
    protected function setWebhookSecret(string $secret): void
    {
        config(['services.mercadopago.webhook_secret' => $secret]);
    }
}
