<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Support;

use App\Models\Order;
use Illuminate\Testing\TestResponse;

/**
 * Monta e envia notificações de webhook do Mercado Pago com assinatura HMAC gerada no teste.
 *
 * Sobre o tempo: a janela anti-replay do controller usa time() nativo do PHP
 * (MercadoPagoWebhookController.php:85), não Carbon::now(). Por isso $this->travelTo()
 * NÃO se aplica ali: o ts do cabeçalho é calculado a partir do relógio real (time()),
 * com deslocamentos em segundos. As margens usadas nos testes (±1 s em torno do limite
 * de 300 s) são suficientes para uma execução que dura milissegundos.
 */
trait SendsMercadoPagoWebhooks
{
    protected const WEBHOOK_URL = '/api/webhooks/mercadopago';

    /**
     * @return array<string, string>
     */
    protected function signedHeaders(string $dataId, string $requestId = 'req-characterization-1', ?int $ts = null, ?string $secret = null): array
    {
        $ts ??= time();
        $secret ??= CharacterizationTestCase::WEBHOOK_SECRET;

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $secret);

        return [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function webhookBody(string $dataId, string $type = 'payment', string $action = 'payment.updated'): array
    {
        return [
            'action' => $action,
            'type' => $type,
            'data' => ['id' => $dataId],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     */
    protected function postWebhook(string $dataId, array $headers = [], ?array $body = null): TestResponse
    {
        return $this->postJson(self::WEBHOOK_URL, $body ?? $this->webhookBody($dataId), $headers);
    }

    protected function postSignedWebhook(string $dataId, ?array $body = null, string $requestId = 'req-characterization-1'): TestResponse
    {
        return $this->postWebhook($dataId, $this->signedHeaders($dataId, $requestId), $body);
    }

    /**
     * Programa a resposta de GET /v1/payments/{id} no cliente falso do SDK.
     */
    protected function apiReturnsPayment(int $id, string $status, Order $order, ?int $amountCents = null): void
    {
        $this->mpHttp->pushJson(
            FakeMercadoPagoHttpClient::payment(
                id: $id,
                status: $status,
                externalReference: $order->order_number,
                amount: ($amountCents ?? $order->total_cents) / 100,
            ),
            200
        );
    }
}
