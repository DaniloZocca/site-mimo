<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CheckoutOrderDTO;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoService
{
    private PaymentClient $client;
    private ?string $webhookSecret;

    public function __construct()
    {
        $accessToken = (string) config('services.mercadopago.access_token', '');
        $this->webhookSecret = config('services.mercadopago.webhook_secret');

        if (! empty($accessToken)) {
            MercadoPagoConfig::setAccessToken($accessToken);
            MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);
        }

        $this->client = new PaymentClient();
    }

    /**
     * Cria cobrança instantânea via PIX com Idempotência estrita (UUIDv4).
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function createPixPayment(Order $order, CheckoutOrderDTO $dto): array
    {
        $amountInReais = (float) round($order->total_cents / 100, 2);
        $idempotencyKey = (string) Str::uuid();

        $requestOptions = new RequestOptions();
        $requestOptions->setCustomHeaders([
            'X-Idempotency-Key: ' . $idempotencyKey,
        ]);

        $payload = [
            'transaction_amount' => $amountInReais,
            'description'        => "MIMOO LAB - Encomenda #{$order->order_number}",
            'payment_method_id'  => 'pix',
            'external_reference' => (string) $order->order_number,
            'notification_url'   => url('/api/webhooks/mercadopago'),
            'payer'              => [
                'email'          => $dto->customerEmail,
                'first_name'     => $dto->customerName,
                'identification' => [
                    'type'   => 'CPF',
                    'number' => preg_replace('/\D/', '', $dto->customerCpf),
                ],
            ],
        ];

        $accessToken = (string) config('services.mercadopago.access_token', '');
        $isMockToken = empty($accessToken) || str_contains($accessToken, 'TEST-000000');

        if ($isMockToken) {
            Log::info("Ambiente de desenvolvimento MIMOO LAB: Simulando emissão de PIX para pedido #{$order->order_number}");
            $mockPix = '00020126580014br.gov.bcb.pix0136' . (string) Str::uuid() . '5204000053039865405' . number_format($amountInReais, 2, '.', '') . '5802BR5913MIMOO LAB 3D6009SAO PAULO62070503***6304ABCD';

            return [
                'payment_id'      => 'pix_dev_' . time(),
                'status'          => 'pending',
                'status_detail'   => 'pending_waiting_transfer',
                'qr_code'         => $mockPix,
                'qr_code_base64'  => '',
                'ticket_url'      => 'https://pix.bcb.gov.br',
                'idempotency_key' => $idempotencyKey,
            ];
        }

        try {
            Log::info("Emitindo cobrança PIX no Mercado Pago para pedido #{$order->order_number}", [
                'amount'          => $amountInReais,
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment = $this->client->create($payload, $requestOptions);

            $pointOfInteraction = $payment->point_of_interaction->transaction_data ?? null;

            return [
                'payment_id'     => (string) $payment->id,
                'status'         => (string) $payment->status,
                'status_detail'  => (string) $payment->status_detail,
                'qr_code'        => (string) ($pointOfInteraction->qr_code ?? ''),
                'qr_code_base64' => (string) ($pointOfInteraction->qr_code_base64 ?? ''),
                'ticket_url'     => (string) ($pointOfInteraction->ticket_url ?? ''),
                'idempotency_key'=> $idempotencyKey,
            ];
        } catch (Exception $e) {
            Log::error("Erro na emissão PIX Mercado Pago para pedido #{$order->order_number}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception("Falha ao comunicar com o gateway PIX: " . $e->getMessage());
        }
    }

    /**
     * Processa cobrança com Cartão de Crédito tokenizado (PCI-DSS Compliance).
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function createCardPayment(Order $order, CheckoutOrderDTO $dto): array
    {
        $amountInReais = (float) round($order->total_cents / 100, 2);
        $idempotencyKey = (string) Str::uuid();

        $requestOptions = new RequestOptions();
        $requestOptions->setCustomHeaders([
            'X-Idempotency-Key: ' . $idempotencyKey,
        ]);

        $payload = [
            'transaction_amount' => $amountInReais,
            'token'              => $dto->cardToken,
            'installments'       => max(1, $dto->installments),
            'payment_method_id'  => $dto->paymentMethodId ?: 'credit_card',
            'description'        => "MIMOO LAB - Encomenda #{$order->order_number}",
            'external_reference' => (string) $order->order_number,
            'notification_url'   => url('/api/webhooks/mercadopago'),
            'payer'              => [
                'email'          => $dto->customerEmail,
                'identification' => [
                    'type'   => 'CPF',
                    'number' => preg_replace('/\D/', '', $dto->customerCpf),
                ],
            ],
        ];

        try {
            Log::info("Processando autorização de cartão para pedido #{$order->order_number}", [
                'amount'          => $amountInReais,
                'installments'    => $dto->installments,
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment = $this->client->create($payload, $requestOptions);

            return [
                'payment_id'     => (string) $payment->id,
                'status'         => (string) $payment->status,
                'status_detail'  => (string) $payment->status_detail,
                'idempotency_key'=> $idempotencyKey,
            ];
        } catch (Exception $e) {
            Log::error("Erro no processamento de cartão Mercado Pago para pedido #{$order->order_number}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception("Falha na autorização do cartão de crédito: " . $e->getMessage());
        }
    }

    /**
     * Consulta reversa de pagamento na API do Mercado Pago.
     *
     * @return array<string, mixed>|null
     */
    public function getPayment(string|int $paymentId): ?array
    {
        try {
            $payment = $this->client->get((int) $paymentId);

            return [
                'id'                 => $payment->id,
                'status'             => $payment->status,
                'status_detail'      => $payment->status_detail,
                'external_reference' => $payment->external_reference,
                'transaction_amount' => $payment->transaction_amount,
            ];
        } catch (Exception $e) {
            Log::error("Exceção ao consultar pagamento #{$paymentId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validação criptográfica do header x-signature (HMAC SHA-256).
     */
    public function validateWebhookSignature(
        string $xSignature,
        string $xRequestId,
        string $dataId,
        int $maxAgeSeconds = 300
    ): bool {
        if (empty($this->webhookSecret)) {
            Log::critical('MERCADOPAGO_WEBHOOK_SECRET não configurado. Assinatura rejeitada (fail-closed).');
            return false;
        }

        $parts = explode(',', $xSignature);
        $ts = null;
        $v1 = null;

        foreach ($parts as $part) {
            $keyValue = explode('=', trim($part), 2);
            if (count($keyValue) === 2) {
                if ($keyValue[0] === 'ts') $ts = $keyValue[1];
                if ($keyValue[0] === 'v1') $v1 = $keyValue[1];
            }
        }

        if (! $ts || ! $v1) return false;

        // Mitigação de Replay Attack (timestamp defasado)
        if (abs(time() - (int) $ts) > $maxAgeSeconds) return false;

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $calculatedHash = hash_hmac('sha256', $manifest, $this->webhookSecret);

        return hash_equals($calculatedHash, $v1);
    }
}
