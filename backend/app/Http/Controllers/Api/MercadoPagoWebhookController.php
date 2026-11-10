<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\GatewayPayment;
use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Services\AfterCommitDispatcher;
use App\Services\MercadoPagoService;
use App\Services\PaymentApprovalValidator;
use App\Services\ProductionWorkflowService;
use Exception;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly PaymentApprovalValidator $approvalValidator,
        private readonly ProductionWorkflowService $workflow,
        private readonly AfterCommitDispatcher $afterCommit,
    ) {}

    /**
     * Ponto de entrada padrão para a rota.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return $this->handle($request);
    }

    /**
     * Endpoint de Ingestão de Webhooks do Mercado Pago (Modelo Fail-Closed).
     */
    public function handle(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        $xSignature = (string) $request->header('x-signature', '');
        $xRequestId = (string) $request->header('x-request-id', '');

        // Identificador do recurso enviado na notificação
        $dataId = (string) ($request->input('data.id') ?? $request->input('id') ?? '');
        $type = (string) ($request->input('type') ?? $request->input('action') ?? '');

        // =========================================================================
        // CAMADA A: PRESENÇA DE HEADERS OBRIGATÓRIOS (FAIL-CLOSED)
        // =========================================================================
        $webhookSecret = (string) config('services.mercadopago.webhook_secret', '');

        // Sem segredo configurado não há como autenticar a notificação: rejeita (fail-closed).
        if (empty($webhookSecret)) {
            Log::critical('⛔ [Webhook Fail-Closed] MERCADOPAGO_WEBHOOK_SECRET não configurado. Notificação rejeitada.', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Unauthorized: Webhook não configurado.',
            ], 401);
        }

        if (! empty($webhookSecret)) {
            if (empty($xSignature) || empty($xRequestId)) {
                Log::warning('⛔ [Webhook Fail-Closed] Requisição rejeitada: Headers x-signature ou x-request-id ausentes.', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'message' => 'Unauthorized: Assinatura ou ID de requisição ausente.',
                ], 401);
            }

            // =====================================================================
            // CAMADA B: DEFESA CONTRA REPLAY ATTACK (MÁXIMO 300s)
            // =====================================================================
            $signatureParts = explode(',', $xSignature);
            $ts = null;
            $v1 = null;

            foreach ($signatureParts as $part) {
                $keyValue = explode('=', trim($part), 2);
                if (count($keyValue) === 2) {
                    if ($keyValue[0] === 'ts') {
                        $ts = $keyValue[1];
                    } elseif ($keyValue[0] === 'v1') {
                        $v1 = $keyValue[1];
                    }
                }
            }

            if (! $ts || ! $v1) {
                Log::warning('⛔ [Webhook Fail-Closed] Formato inválido no header x-signature.', ['x_signature' => $xSignature]);

                return response()->json(['message' => 'Unauthorized: Formato de assinatura inválido.'], 401);
            }

            $currentTimestamp = time();
            $requestTimestamp = (int) $ts;

            if (abs($currentTimestamp - $requestTimestamp) > 300) {
                Log::warning('⛔ [Webhook Fail-Closed] Replay Attack mitigado: Diferença de timestamp superior a 300s.', [
                    'ts' => $requestTimestamp,
                    'now' => $currentTimestamp,
                ]);

                return response()->json(['message' => 'Unauthorized: Timestamp expirado.'], 401);
            }

            // =====================================================================
            // CAMADA C: VALIDAÇÃO CRIPTOGRÁFICA HMAC-SHA256 (hash_equals)
            // =====================================================================
            $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
            $calculatedHash = hash_hmac('sha256', $manifest, $webhookSecret);

            if (! hash_equals($calculatedHash, $v1)) {
                Log::warning('⛔ [Webhook Fail-Closed] Assinatura HMAC inválida (Timing Attack / Spoofing bloqueado).', [
                    'ip' => $request->ip(),
                    'manifest' => $manifest,
                ]);

                return response()->json(['message' => 'Unauthorized: Assinatura criptográfica inválida.'], 401);
            }
        }

        // Se for evento que não é de pagamento direto, retornar 200
        if (empty($dataId) || (! str_contains($type, 'payment') && ! empty($type))) {
            return response()->json(['message' => 'Evento ignorado (não é pagamento direto)'], 200);
        }

        // =========================================================================
        // CAMADA D: CONSULTA REVERSA ATIVA (DOUBLE-CHECK NA API OFICIAL)
        // =========================================================================
        $paymentData = $this->mercadoPagoService->getPayment($dataId);

        if (! $paymentData) {
            Log::error("❌ Falha na consulta reversa do pagamento #{$dataId} no gateway Mercado Pago.");

            return response()->json(['message' => 'Falha na verificação reversa do pagamento'], 422);
        }

        $payment = GatewayPayment::fromMercadoPago($paymentData);
        $paymentStatus = $payment->status;
        $externalReference = (string) ($payment->externalReference ?? '');

        if (empty($externalReference)) {
            Log::warning("⚠️ Pagamento #{$dataId} sem external_reference associada.");

            return response()->json(['message' => 'external_reference ausente'], 200);
        }

        // =========================================================================
        // CAMADA E: CONCORRÊNCIA, BLOQUEIO PESSIMISTA & MUTAÇÃO ATÔMICA
        // =========================================================================
        try {
            return DB::transaction(function () use (
                $externalReference,
                $paymentStatus,
                $payment,
                $dataId,
                $startTime
            ): JsonResponse {
                /** @var Order|null $order */
                $order = Order::where('order_number', $externalReference)
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    Log::warning("⚠️ Pedido não localizado para a external_reference '{$externalReference}'");

                    return response()->json(['message' => 'Pedido não localizado'], 200);
                }

                // Idempotência: Se o pedido já avançou para a fila de impressão ou além, responde 200
                if ($order->status !== OrderStatus::PENDING_PAYMENT) {
                    // Dinheiro recebido para pedido cancelado: alerta e registro interno para estorno (não reabre)
                    if ($order->status === OrderStatus::CANCELLED && $paymentStatus === 'approved') {
                        $this->approvalValidator->reportApprovedAfterCancellation($order, $payment);
                    } else {
                        Log::info("ℹ️ Evento idempotente recebido: Pedido #{$order->order_number} já está em '{$order->status->value}'.");
                    }

                    return response()->json([
                        'message' => 'Evento idempotente já processado.',
                        'order_status' => $order->status->value,
                    ], 200);
                }

                // Transição para a Fila de Produção 3D: só com o pagamento exatamente igual ao esperado
                if ($paymentStatus === 'approved') {
                    $reasons = $this->approvalValidator->validate($order, $payment);

                    if ($reasons !== []) {
                        // Divergência é caso de revisão humana, não de reenvio: 200 e pedido segue pendente
                        $this->approvalValidator->reportDivergence($order, $payment, $reasons, 'webhook');

                        return response()->json([
                            'message' => 'Pagamento em revisão: divergência registrada.',
                            'order_status' => OrderStatus::PENDING_PAYMENT->value,
                        ], 200);
                    }

                    // Regra 5: pelo serviço, com origem esperada (false = o pedido já não está pendente)
                    $updated = $this->workflow->transitionFrom(
                        $order,
                        OrderStatus::PENDING_PAYMENT,
                        OrderStatus::IN_QUEUE,
                        "Pagamento aprovado via Webhook Mercado Pago (Payment ID: {$dataId})",
                        extra: ['paid_at' => now()],
                        log: ['code' => ProductionLog::CODE_PAYMENT_APPROVED, 'reference' => $payment->id],
                    );

                    if (! $updated) {
                        Log::info("ℹ️ Evento idempotente recebido: Pedido #{$order->order_number} já está em '{$order->status->value}'.");

                        return response()->json([
                            'message' => 'Evento idempotente já processado.',
                            'order_status' => $order->status->value,
                        ], 200);
                    }

                    // Dispara evento de domínio para listeners de e-mail e WebSocket da fazenda. Sai depois do commit;
                    // uma falha vai para o log e o webhook responde 200 mesmo assim (M-32)
                    $this->afterCommit->event($order, new OrderPaidEvent($order, (string) $dataId));

                    $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

                    Log::info("✅ [Webhook] Pedido #{$order->order_number} confirmado e movido para a Fila de Impressão 3D ({$executionTimeMs}ms)", [
                        'order_id' => $order->id,
                        'payment_id' => $dataId,
                        'total' => $order->total_cents,
                    ]);

                    return response()->json([
                        'message' => 'Pagamento aprovado e pedido encaminhado para a fila de manufatura 3D.',
                        'order_status' => OrderStatus::IN_QUEUE->value,
                    ], 200);
                }

                // Casos de Cancelamento / Recusa
                if (in_array($paymentStatus, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
                    // Só cancela pelo pagamento do próprio pedido (mesma regra da aprovação): com id gravado no
                    // checkout, a recusa de outro pagamento com a mesma external_reference é ignorada.
                    $knownIds = $order->payments()->whereNotNull('external_id')->pluck('external_id')->map(fn ($id): string => (string) $id);
                    if ($knownIds->isNotEmpty() && ! $knownIds->contains($payment->id)) {
                        Log::warning("⚠️ Recusa ignorada: pagamento #{$payment->id} não pertence ao pedido #{$order->order_number}.", [
                            'order_id' => $order->id,
                            'payment_id' => $payment->id,
                        ]);

                        return response()->json([
                            'message' => 'Notificação ignorada: pagamento não pertence a este pedido.',
                            'payment_status' => $paymentStatus,
                        ], 200);
                    }

                    // Regra 5: pelo serviço, com origem esperada (false = painel ou outra notificação mudou o
                    // pedido entre a leitura e a gravação)
                    $updated = $this->workflow->transitionFrom(
                        $order,
                        OrderStatus::PENDING_PAYMENT,
                        OrderStatus::CANCELLED,
                        "Status de gateway recebido: {$paymentStatus}",
                    );

                    if (! $updated) {
                        Log::info("ℹ️ Evento idempotente recebido: Pedido #{$order->order_number} já está em '{$order->status->value}'.");

                        return response()->json([
                            'message' => 'Evento idempotente já processado.',
                            'order_status' => $order->status->value,
                        ], 200);
                    }

                    Log::warning("⚠️ Pedido #{$order->order_number} cancelado via webhook com status: {$paymentStatus}");
                }

                return response()->json([
                    'message' => 'Notificação processada com sucesso.',
                    'payment_status' => $paymentStatus,
                ], 200);
            });

        } catch (Exception $e) {
            // M-45: pedido travado por outra operação (espera de 10 s esgotada, deadlock ou "Record has changed"):
            // nada foi gravado. Resposta não-2xx para o Mercado Pago reenviar; o reenvio processa normalmente.
            if (app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                Log::warning("⏳ Webhook do pedido #{$externalReference} adiado: o pedido estava travado por outra operação. O Mercado Pago vai reenviar.", [
                    'payment_id' => $dataId,
                    'exception' => $e::class,
                ]);

                return response()->json(['message' => 'Pedido em processamento. Tente novamente.'], 503)
                    ->header('Retry-After', '5');
            }

            Log::error("❌ Exceção ao processar webhook para #{$externalReference}: ".$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Erro interno ao processar transação de webhook.'], 500);
        }
    }
}
