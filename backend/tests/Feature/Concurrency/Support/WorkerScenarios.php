<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency\Support;

use App\DTOs\GatewayPayment;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\CheckoutController;
use App\Models\Order;
use App\Services\ProductionWorkflowService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use InvalidArgumentException;
use MercadoPago\MercadoPagoConfig;
use ReflectionMethod;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * O que o processo auxiliar (bin/worker.php) sabe fazer. Cada cenário é o caminho real da loja:
 * webhook, checkout e cadastro pelo kernel HTTP; cancelar e liberar o cartão pelo mesmo código do painel e
 * do checkout.
 */
final class WorkerScenarios
{
    private FakeMercadoPagoHttpClient $mercadoPago;

    public function __construct(private readonly Application $app)
    {
        $this->mercadoPago = new FakeMercadoPagoHttpClient;
        MercadoPagoConfig::setHttpClient($this->mercadoPago);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function run(string $scenario, array $args): array
    {
        $result = match ($scenario) {
            'webhook' => $this->webhook($args),
            'checkout' => $this->checkout($args),
            'register' => $this->register($args),
            'cancel' => $this->cancel($args),
            'card_release' => $this->cardRelease($args),
            default => throw new InvalidArgumentException("Cenário desconhecido: {$scenario}"),
        };

        return $result + ['mp_calls' => count($this->mercadoPago->requests)];
    }

    /**
     * Notificação assinada do Mercado Pago; a consulta reversa devolve o pagamento descrito em $args.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function webhook(array $args): array
    {
        $paymentId = (int) $args['payment_id'];
        $this->mercadoPago->pushJson(FakeMercadoPagoHttpClient::payment(
            id: $paymentId,
            status: (string) $args['status'],
            externalReference: (string) $args['order_number'],
            amount: ((int) $args['amount_cents']) / 100,
        ), 200);

        return $this->http('POST', '/api/webhooks/mercadopago', [
            'action' => 'payment.updated',
            'type' => 'payment',
            'data' => ['id' => (string) $paymentId],
        ], Harness::signedWebhookServer((string) $paymentId, (string) ($args['request_id'] ?? uniqid('req-', true))));
    }

    /**
     * POST /api/checkout com a Idempotency-Key dada; o Mercado Pago responde a criação do PIX.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function checkout(array $args): array
    {
        $this->mercadoPago->pushJson(FakeMercadoPagoHttpClient::payment(
            id: (int) $args['payment_id'],
            status: 'pending',
            statusDetail: 'pending_waiting_transfer',
        ) + ['point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-copia-e-cola', 'qr_code_base64' => 'aW1n', 'ticket_url' => 'https://exemplo.test/pix']]], 201);

        return $this->http('POST', '/api/checkout', (array) $args['payload'], [
            'HTTP_IDEMPOTENCY_KEY' => (string) $args['key'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function register(array $args): array
    {
        return $this->http('POST', '/api/conta/register', $args);
    }

    /**
     * "Cancelar" do painel: transitionFrom com a origem que a tela mostrava (OrderResource::applyPanelTransition).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function cancel(array $args): array
    {
        $this->bootConsole();
        $order = Order::findOrFail($args['order_id']);

        $applied = $this->app->make(ProductionWorkflowService::class)->transitionFrom(
            $order,
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::CANCELLED,
            'Cancelado pelo painel (teste de concorrência)',
        );

        return ['applied' => $applied];
    }

    /**
     * Cartão aprovado na criação do pagamento: o mesmo método privado que o checkout chama depois do gateway.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function cardRelease(array $args): array
    {
        $this->bootConsole();
        $order = Order::findOrFail($args['order_id']);
        $payment = GatewayPayment::fromMercadoPago(FakeMercadoPagoHttpClient::payment(
            id: (int) $args['payment_id'],
            status: 'approved',
            externalReference: $order->order_number,
            amount: ((int) $args['amount_cents']) / 100,
        ));

        $release = new ReflectionMethod(CheckoutController::class, 'releaseApprovedCardPayment');
        $released = $release->invoke($this->app->make(CheckoutController::class), $order, $payment);

        return ['released' => $released];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $server
     * @return array<string, mixed>
     */
    private function http(string $method, string $uri, array $body, array $server = []): array
    {
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create($uri, $method, [], [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], (string) json_encode($body));

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true),
        ];
    }

    private function bootConsole(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();
    }
}
