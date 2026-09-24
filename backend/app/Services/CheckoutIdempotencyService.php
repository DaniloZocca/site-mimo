<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Idempotência do POST /api/checkout (header Idempotency-Key).
 * A garantia contra duplicata é o índice UNIQUE em orders.idempotency_key; este serviço só
 * identifica a tentativa, compara o fingerprint e monta a resposta (a original ou o replay).
 */
class CheckoutIdempotencyService
{
    public const HEADER = 'Idempotency-Key';

    /** Segundos sugeridos ao cliente para tentar de novo enquanto o primeiro envio não terminou. */
    public const RETRY_AFTER_SECONDS = 2;

    /** Cartão aprovado com divergência (PaymentApprovalValidator): pedido em revisão, sem nova cobrança. */
    public const PAYMENT_UNDER_REVIEW = 'payment_under_review';

    public const PAYMENT_UNDER_REVIEW_MESSAGE = 'Recebemos seu pagamento, mas ele precisa de uma conferência manual antes de irmos para a produção. Não pague de novo: entraremos em contato pelo e-mail do pedido.';

    /**
     * Chave enviada pelo front, normalizada; null se ausente ou se não for um UUID.
     */
    public function keyFrom(Request $request): ?string
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        return Str::isUuid($key) ? strtolower($key) : null;
    }

    /**
     * SHA-256 do payload canônico: chaves ordenadas recursivamente e sem card_token
     * (de uso único; muda a cada tokenização do cartão).
     *
     * @param  array<mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        unset($payload['card_token']);
        if (isset($payload['payment']) && is_array($payload['payment'])) {
            unset($payload['payment']['card_token']);
        }

        return hash('sha256', (string) json_encode(
            self::canonical($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * Fingerprint do corpo bruto da requisição (antes das normalizações do FormRequest).
     */
    public function fingerprintOf(Request $request): string
    {
        $payload = json_decode((string) $request->getContent(), true);

        return self::fingerprint(is_array($payload) ? $payload : $request->all());
    }

    public function findByKey(string $key): ?Order
    {
        return Order::where('idempotency_key', $key)->first();
    }

    public function missingKeyResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'idempotency_key_required',
            'message' => 'Não foi possível identificar esta tentativa de compra. Recarregue a página e tente de novo.',
        ], 400);
    }

    /**
     * Resposta para uma chave já usada: payload diferente (422), primeiro envio ainda em
     * andamento (409) ou replay da resposta original, sem chamar o gateway de novo.
     */
    public function responseForExisting(Order $order, string $fingerprint): JsonResponse
    {
        if (! hash_equals((string) $order->request_fingerprint, $fingerprint)) {
            return response()->json([
                'code' => 'idempotency_key_reused',
                'message' => 'Esta tentativa de compra já foi usada com outros dados. Revise o pedido e tente de novo.',
            ], 422);
        }

        $payment = $order->payments()->orderByDesc('created_at')->first();

        if (! $payment) {
            return response()->json([
                'code' => 'request_in_progress',
                'message' => 'Seu pedido ainda está sendo processado. Aguarde alguns segundos.',
            ], 409)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        return $this->checkoutResponse($order, $payment)->header('Idempotent-Replayed', 'true');
    }

    /**
     * Resposta do checkout montada a partir do que foi persistido. Usada no primeiro envio e no
     * replay, o que garante respostas idênticas (mesmo status HTTP e mesmo corpo).
     */
    public function checkoutResponse(Order $order, OrderPayment $payment): JsonResponse
    {
        if ($payment->http_status === 202) {
            return response()->json([
                'code' => self::PAYMENT_UNDER_REVIEW,
                'message' => (string) $payment->failure_message,
                'order_number' => $order->order_number,
                'tracking_token' => $order->tracking_token,
                'status' => $payment->order_status,
            ], 202);
        }

        if ($payment->http_status !== 201) {
            return response()->json([
                'message' => (string) $payment->failure_message,
                'order_number' => $order->order_number,
            ], $payment->http_status);
        }

        $status = OrderStatus::from($payment->order_status);

        return response()->json([
            'message' => 'Pedido gerado com sucesso!',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'tracking_token' => $order->tracking_token,
            'status' => $status->value,
            'status_label' => $status->getLabel(),
            'total_cents' => $order->total_cents,
            'total_formatted' => 'R$ '.number_format($order->total_cents / 100, 2, ',', '.'),
            'payment' => [
                'method' => $payment->method,
                'payment_id' => $payment->external_id,
                'status' => $payment->status,
                'status_detail' => $payment->status_detail,
                'qr_code' => $payment->qr_code,
                'qr_code_base64' => $payment->qr_code_base64,
                'ticket_url' => $payment->ticket_url,
                'idempotency_key' => $order->payment_idempotency_key,
            ],
        ], 201);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonical($v);
            }
        }

        return $value;
    }
}
