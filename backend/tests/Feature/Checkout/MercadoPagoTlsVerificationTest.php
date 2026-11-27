<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\File;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\HttpRequest;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-06 (S1 da auditoria de 2026-11-27): o SDK do Mercado Pago fica sempre em `SERVER`. No modo `LOCAL` o
 * `MPDefaultHttpClient` desliga `CURLOPT_SSL_VERIFYPEER` e `CURLOPT_SSL_VERIFYHOST`, e o servidor aceitaria
 * qualquer certificado ao falar com `api.mercadopago.com` (Access Token lido e "aprovado" falso no meio do caminho).
 */
class MercadoPagoTlsVerificationTest extends CharacterizationTestCase
{
    protected function tearDown(): void
    {
        MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::SERVER);

        parent::tearDown();
    }

    public function test_servico_com_token_deixa_o_sdk_em_server_mesmo_com_local_ligado_antes(): void
    {
        MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);

        new MercadoPagoService;

        $this->assertSame(MercadoPagoConfig::SERVER, MercadoPagoConfig::getRuntimeEnviroment());
    }

    public function test_servico_sem_token_tambem_deixa_o_sdk_em_server(): void
    {
        config(['services.mercadopago.access_token' => '']);
        MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);

        new MercadoPagoService;

        $this->assertSame(MercadoPagoConfig::SERVER, MercadoPagoConfig::getRuntimeEnviroment());
    }

    public function test_chamada_pelo_cliente_http_do_sdk_mantem_a_verificacao_do_certificado(): void
    {
        $transport = new class(json_encode(FakeMercadoPagoHttpClient::payment(123456, 'approved', 'MIMOO-TLS00001', 64.9))) implements HttpRequest
        {
            /** @var array<int, mixed> */
            public array $options = [];

            public function __construct(private readonly string $body) {}

            public function setOptionArray(array $value): void
            {
                $this->options = $value;
            }

            public function execute(): bool|string
            {
                return $this->body;
            }

            public function getInfo(mixed $name): mixed
            {
                return $name === CURLINFO_HTTP_CODE ? 200 : null;
            }

            public function close(): void {}

            public function error(): string
            {
                return '';
            }
        };
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient($transport));

        $payment = (new MercadoPagoService)->getPayment('123456');

        $this->assertSame('approved', $payment['status'] ?? null);
        $this->assertSame('https://api.mercadopago.com/v1/payments/123456', $transport->options[CURLOPT_URL] ?? null);
        $this->assertNotFalse($transport->options[CURLOPT_SSL_VERIFYPEER] ?? true, 'O SDK desligou a conferência do certificado (VERIFYPEER).');
        $this->assertNotFalse($transport->options[CURLOPT_SSL_VERIFYHOST] ?? true, 'O SDK desligou a conferência do nome do servidor (VERIFYHOST).');
    }

    public function test_nenhum_codigo_da_aplicacao_liga_o_modo_local_do_sdk(): void
    {
        $offenders = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = $file->getContents();
            $path = $file->getRelativePathname();
            // O SDK compara com o texto 'local': procurar só a constante deixaria passar setRuntimeEnviroment('local').
            $setsMode = preg_match_all('/setRuntimeEnviroment\s*\(([^)]*)\)/', $code, $calls) > 0;
            $onlyServerInService = $path === 'Services/MercadoPagoService.php'
                && $calls[1] === ['MercadoPagoConfig::SERVER'];

            if (str_contains($code, 'MercadoPagoConfig::LOCAL') || ($setsMode && ! $onlyServerInService)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Só o MercadoPagoService define o modo do SDK, e só como SERVER: o LOCAL desliga a verificação do certificado HTTPS.');
    }
}
