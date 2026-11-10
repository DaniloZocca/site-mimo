<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Exceptions\PaymentGatewayNotConfiguredException;
use App\Models\Order;
use App\Models\ProductionLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-52: fora de `local`/`testing`, com Access Token vazio ou de exemplo (`TEST-000000`), o webhook não consulta
 * o Mercado Pago (a chamada sairia sem autorização e voltaria 401). A resposta continua o 422 de hoje, para o
 * Mercado Pago reenviar; nenhum pedido muda.
 */
class WebhookWithoutAccessTokenTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private const REAL_TOKEN = 'APP_USR-1234567890-111111-producao';

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaidEvent::class]);
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    private function webhookIn(string $environment, ?string $accessToken): TestResponse
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['services.mercadopago.access_token' => $accessToken]);

        return $this->postSignedWebhook('123');
    }

    private function assertNothingChangedAndNoCall(TestResponse $response): void
    {
        $response->assertStatus(422)
            ->assertExactJson(['message' => 'Falha na verificação reversa do pagamento']);

        $this->assertCount(0, $this->mpHttp->requests);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertNull($this->order->paid_at);
        $this->assertSame(0, ProductionLog::count());
        Event::assertNotDispatched(OrderPaidEvent::class);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function withoutRealToken(): array
    {
        return [
            'producao, token vazio' => ['production', ''],
            'producao, token null' => ['production', null],
            'producao, token de exemplo' => ['production', 'TEST-000000'],
            'staging, token vazio' => ['staging', ''],
            'ambiente desconhecido, token so de espacos' => ['homologacao', '   '],
        ];
    }

    #[DataProvider('withoutRealToken')]
    public function test_sem_token_de_verdade_fora_de_local_testing_nao_consulta_o_mercado_pago(string $environment, ?string $token): void
    {
        $this->assertNothingChangedAndNoCall($this->webhookIn($environment, $token));
    }

    public function test_falha_vai_para_o_log_so_com_a_classe_e_sem_o_token(): void
    {
        Log::spy();

        $this->webhookIn('production', 'TEST-000000')->assertStatus(422);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Exceção ao consultar pagamento #123.'
                && $context['payment_id'] === '123'
                && $context['exception'] === PaymentGatewayNotConfiguredException::class
                && ! str_contains((string) json_encode($context), 'TEST-000000'))
            ->once();
    }

    public function test_reenvio_depois_de_configurar_o_token_aprova_normalmente(): void
    {
        $this->assertNothingChangedAndNoCall($this->webhookIn('production', ''));

        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->webhookIn('production', self::REAL_TOKEN)
            ->assertOk()
            ->assertJsonPath('order_status', 'in_queue');

        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame('GET', $this->mpHttp->requests[0]->getMethod());
        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        Event::assertDispatchedTimes(OrderPaidEvent::class, 1);
    }

    public function test_producao_com_token_real_continua_consultando_o_mercado_pago(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->webhookIn('production', self::REAL_TOKEN)->assertOk();

        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertStringContainsString('/v1/payments/123', $this->mpHttp->requests[0]->getUri());
        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_em_testing_sem_token_a_consulta_segue_como_antes(): void
    {
        // Em local/testing nada muda: a chamada sai (a API de verdade a recusaria com 401).
        $this->mpHttp->pushApiError(401);

        $this->webhookIn('testing', '')->assertStatus(422);

        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
    }
}
