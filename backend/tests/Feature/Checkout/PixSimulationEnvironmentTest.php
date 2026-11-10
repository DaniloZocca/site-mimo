<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Enums\OrderStatus;
use App\Exceptions\PaymentGatewayNotConfiguredException;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-51: o PIX simulado (QR falso, payment id `pix_dev_`) só existe em `local` e `testing`.
 * Em qualquer outro ambiente, Access Token vazio ou de exemplo (`TEST-000000`) é falha de
 * gateway: 502, pedido `pending_payment`, nenhuma chamada ao Mercado Pago.
 */
class PixSimulationEnvironmentTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const PIX_ERROR_MESSAGE = 'Não conseguimos gerar o seu PIX agora 😕 Nenhuma cobrança foi feita. Tente de novo em alguns instantes.';

    private function checkoutIn(string $environment, ?string $accessToken, string $method = 'pix', ?string $key = null): TestResponse
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['services.mercadopago.access_token' => $accessToken]);

        ['payload' => $payload] = $this->simpleCheckout($method);

        return $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function assertGatewayFailureWithoutCallingMercadoPago(TestResponse $response): void
    {
        $response->assertStatus(502)
            ->assertJsonPath('code', 'payment_gateway_error')
            ->assertJsonPath('message', self::PIX_ERROR_MESSAGE)
            ->assertJsonMissingPath('payment');
        $this->assertStringNotContainsString('pix_dev_', $response->getContent());
        $this->assertStringNotContainsString('br.gov.bcb.pix', $response->getContent());

        $this->assertCount(0, $this->mpHttp->requests);

        $order = Order::sole();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $payment = $order->payments()->sole();
        $this->assertSame('error', $payment->status);
        $this->assertSame(502, $payment->http_status);
        $this->assertNull($payment->external_id);
        $this->assertNull($payment->qr_code);
    }

    public function test_producao_sem_token_nao_simula_pix_e_responde_falha_de_gateway(): void
    {
        $this->assertGatewayFailureWithoutCallingMercadoPago($this->checkoutIn('production', ''));
    }

    public function test_producao_sem_token_nenhum_null_tambem_nao_simula(): void
    {
        $this->assertGatewayFailureWithoutCallingMercadoPago($this->checkoutIn('production', null));
    }

    public function test_producao_com_token_de_exemplo_nao_simula_pix(): void
    {
        $this->assertGatewayFailureWithoutCallingMercadoPago($this->checkoutIn('production', 'TEST-000000'));
    }

    public function test_staging_sem_token_nao_simula_pix(): void
    {
        $this->assertGatewayFailureWithoutCallingMercadoPago($this->checkoutIn('staging', ''));
    }

    public function test_falha_por_token_ausente_vai_para_o_log_so_com_a_classe_e_sem_o_token(): void
    {
        Log::spy();

        $this->checkoutIn('production', 'TEST-000000')->assertStatus(502);

        $orderNumber = Order::sole()->order_number;
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === "Falha ao processar pagamento do pedido #{$orderNumber}."
                && $context['exception'] === PaymentGatewayNotConfiguredException::class
                && ! str_contains(json_encode($context), 'TEST-000000'))
            ->once();
    }

    public function test_reenvio_com_a_mesma_chave_devolve_o_mesmo_502_sem_chamar_o_gateway(): void
    {
        $key = (string) Str::uuid();
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.mercadopago.access_token' => '']);
        ['payload' => $payload] = $this->simpleCheckout('pix');

        $first = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key]);
        $second = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key]);

        $first->assertStatus(502);
        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->json(), $second->json());
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertCount(0, $this->mpHttp->requests);
        $this->assertSame(1, Order::count());
    }

    public function test_producao_com_token_real_continua_chamando_o_mercado_pago(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(555, 'pending'));

        $response = $this->checkoutIn('production', self::ACCESS_TOKEN);

        $response->assertStatus(201)->assertJsonPath('payment.payment_id', '555');
        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame('/v1/payments', $this->mpHttp->requests[0]->getUri());
    }

    public function test_local_sem_token_continua_simulando_o_pix(): void
    {
        $response = $this->checkoutIn('local', '');

        $response->assertStatus(201);
        $this->assertStringStartsWith('pix_dev_', $response->json('payment.payment_id'));
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_cartao_em_producao_sem_token_nao_chama_o_mercado_pago(): void
    {
        $response = $this->checkoutIn('production', '', 'credit_card');

        $order = Order::sole();
        $response->assertStatus(502)
            ->assertJsonPath('code', 'payment_gateway_error')
            ->assertJsonPath('order_number', $order->order_number);
        $this->assertCount(0, $this->mpHttp->requests);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame('error', $order->payments()->sole()->status);
    }

    public function test_cartao_em_local_sem_token_segue_como_antes_e_chama_a_api(): void
    {
        // Em local o cartão não tem simulação: a chamada segue (e a API de verdade a recusaria).
        $this->mpHttp->pushApiError(401);

        $this->checkoutIn('local', '', 'credit_card')->assertStatus(502);

        $this->assertCount(1, $this->mpHttp->requests);
    }
}
