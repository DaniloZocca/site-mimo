<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MercadoPagoService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPagoService
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
        $type   = (string) ($request->input('type') ?? $request->input('action') ?? '');

        // =========================================================================
        // CAMADA A: PRESENÇA DE HEADERS OBRIGATÓRIOS (FAIL-CLOSED)
        // =========================================================================
        $webhookSecret = (string) config('services.mercadopago.webhook_secret', '');

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
                    'ts'  => $requestTimestamp,
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
                    'ip'       => $request->ip(),
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

        $paymentStatus      = (string) ($paymentData['status'] ?? '');
        $externalReference  = (string) ($paymentData['external_reference'] ?? '');
        $transactionAmount  = (float) ($paymentData['transaction_amount'] ?? 0.0);
        $paidAmountInCents  = (int) round($transactionAmount * 100);

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
                $dataId,
                $paidAmountInCents,
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
                    Log::info("ℹ️ Evento idempotente recebido: Pedido #{$order->order_number} já está em '{$order->status->value}'.");
                    return response()->json([
                        'message'      => 'Evento idempotente já processado.',
                        'order_status' => $order->status->value,
                    ], 200);
                }

                // Validação Anti-Fraude: O valor pago deve ser idêntico ou superior ao total da compra
                if ($paidAmountInCents < $order->total_cents) {
                    Log::critical("🚨 [ALERTA DE FRAUDE] Valor pago (R$ " . ($paidAmountInCents / 100) . ") é inferior ao total do pedido #{$order->order_number} (R$ " . ($order->total_cents / 100) . ")");
                    return response()->json(['message' => 'Divergência de valores financeiros detectada.'], 422);
                }

                // Transição para a Fila de Produção 3D
                if ($paymentStatus === 'approved') {
                    $previousStatus = $order->status->value;

                    $order->status  = OrderStatus::IN_QUEUE;
                    $order->paid_at = now();
                    $order->save();

                    $order->productionLogs()->create([
                        'previous_status' => $previousStatus,
                        'new_status'      => OrderStatus::IN_QUEUE->value,
                        'notes'           => "Pagamento aprovado via Webhook Mercado Pago (Payment ID: {$dataId})",
                        'created_at'      => now(),
                    ]);

                    // Dispara evento de domínio para listeners de e-mail e WebSocket da fazenda
                    event(new OrderPaidEvent($order, (string) $dataId));

                    $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

                    Log::info("✅ [Webhook] Pedido #{$order->order_number} confirmado e movido para a Fila de Impressão 3D ({$executionTimeMs}ms)", [
                        'order_id'   => $order->id,
                        'payment_id' => $dataId,
                        'total'      => $order->total_cents,
                    ]);

                    return response()->json([
                        'message'      => 'Pagamento aprovado e pedido encaminhado para a fila de manufatura 3D.',
                        'order_status' => OrderStatus::IN_QUEUE->value,
                    ], 200);
                }

                // Casos de Cancelamento / Recusa
                if (in_array($paymentStatus, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
                    $previousStatus = $order->status->value;

                    $order->status = OrderStatus::CANCELLED;
                    $order->save();

                    $order->productionLogs()->create([
                        'previous_status' => $previousStatus,
                        'new_status'      => OrderStatus::CANCELLED->value,
                        'notes'           => "Status de gateway recebido: {$paymentStatus}",
                        'created_at'      => now(),
                    ]);

                    Log::warning("⚠️ Pedido #{$order->order_number} cancelado via webhook com status: {$paymentStatus}");
                }

                return response()->json([
                    'message'        => 'Notificação processada com sucesso.',
                    'payment_status' => $paymentStatus,
                ], 200);
            });

        } catch (Exception $e) {
            Log::error("❌ Exceção ao processar webhook para #{$externalReference}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Erro interno ao processar transação de webhook.'], 500);
        }
    }
}
