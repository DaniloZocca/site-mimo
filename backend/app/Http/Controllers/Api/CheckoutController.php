<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\CheckoutOrderDTO;
use App\DTOs\GatewayPayment;
use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Exceptions\ShippingPriceChangedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Jobs\ProcessOrderPaidNotification;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Services\CheckoutIdempotencyService;
use App\Services\MercadoPagoService;
use App\Services\OrderCreationService;
use App\Services\PaymentApprovalValidator;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly CheckoutIdempotencyService $idempotency,
        private readonly PaymentApprovalValidator $approvalValidator,
    ) {}

    /**
     * Orquestração segura do Checkout com Zero-Trust Pricing e Atomicidade.
     */
    public function __invoke(CheckoutRequest $request): JsonResponse
    {
        // 0. Idempotência: mesma Idempotency-Key → mesma resposta, sem novo pedido nem nova cobrança
        $idempotencyKey = $this->idempotency->keyFrom($request);
        if ($idempotencyKey === null) {
            return $this->idempotency->missingKeyResponse();
        }

        $fingerprint = $this->idempotency->fingerprintOf($request);
        $existing = $this->idempotency->findByKey($idempotencyKey);
        if ($existing) {
            return $this->idempotency->responseForExisting($existing, $fingerprint);
        }

        // 1. Converte Request Validado em DTO Imutável (PHP 8.2+)
        $orderDTO = CheckoutOrderDTO::fromRequest($request);

        // 2. Criação Atômica do Pedido no PostgreSQL com Zero-Trust Pricing
        // REGRA DE OURO: A transação DB commita ANTES de qualquer chamada de rede externa
        try {
            // Frete recalculado no servidor (chamada externa, fora de transação); o valor do cliente só detecta divergência
            $shipping = $this->orderCreationService->resolveShipping($orderDTO);
            $order = $this->orderCreationService->createOrder($orderDTO, $shipping, $idempotencyKey, $fingerprint);
        } catch (UniqueConstraintViolationException $e) {
            // Corrida: outro envio com a mesma chave criou o pedido primeiro. O UNIQUE impediu a duplicata.
            $existing = $this->idempotency->findByKey($idempotencyKey);
            if (! $existing) {
                throw $e;
            }

            return $this->idempotency->responseForExisting($existing, $fingerprint);
        } catch (ShippingPriceChangedException $e) {
            return response()->json([
                'code' => 'shipping_price_changed',
                'message' => $e->getMessage(),
                'shipping_service' => $e->serverOption->serviceCode,
                'shipping_cost_cents' => $e->serverOption->priceInCents,
                'shipping_options' => array_map(fn ($option) => $option->toArray(), $e->options),
            ], 409);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::warning('Validação de catálogo falhou no checkout: '.$e->getMessage());

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        // 3. Emissão de Cobrança no Gateway (Fora de transações de banco de dados)
        try {
            if ($orderDTO->paymentMethod === 'pix') {
                $paymentResult = $this->mercadoPagoService->createPixPayment($order, $orderDTO);
            } else {
                $paymentResult = $this->mercadoPagoService->createCardPayment($order, $orderDTO);
            }
        } catch (Exception $e) {
            // 5. Tratamento de Falha no Gateway e Atualização de Auditoria
            Log::error("Falha ao processar pagamento do pedido #{$order->order_number}: ".$e->getMessage());

            $previousStatus = $order->status->value;
            $order->status = OrderStatus::CANCELLED;
            $order->save();

            $order->productionLogs()->create([
                'previous_status' => $previousStatus,
                'new_status' => OrderStatus::CANCELLED->value,
                'notes' => 'Cobrança não concluída no gateway: '.$e->getMessage(),
                'created_at' => now(),
            ]);

            // Resposta persistida: um reenvio com a mesma chave recebe exatamente esta resposta
            $payment = $order->payments()->create([
                'provider' => 'mercadopago',
                'method' => $orderDTO->paymentMethod,
                'status' => 'error',
                'failure_message' => 'Não foi possível processar a cobrança. '.$e->getMessage(),
                'http_status' => 422,
                'order_status' => $order->status->value,
            ]);

            return $this->idempotency->checkoutResponse($order, $payment);
        }

        // 4. Aprovação instantânea (cartão): só libera a produção se o pagamento for exatamente o esperado.
        // Fora do try do gateway: a cobrança já existe, então um erro aqui nunca pode cancelar o pedido.
        $underReview = false;
        if ($paymentResult['status'] === 'approved') {
            $underReview = ! $this->releaseApprovedCardPayment($order, GatewayPayment::fromMercadoPago($paymentResult));
        }

        // 5. Persiste o resultado do gateway e a resposta dada (base do replay idempotente)
        $payment = $order->payments()->create([
            'provider' => 'mercadopago',
            'method' => $orderDTO->paymentMethod,
            'external_id' => (string) $paymentResult['payment_id'],
            'status' => (string) $paymentResult['status'],
            'status_detail' => $paymentResult['status_detail'] ?? null,
            'qr_code' => $paymentResult['qr_code'] ?? null,
            'qr_code_base64' => $paymentResult['qr_code_base64'] ?? null,
            'ticket_url' => $paymentResult['ticket_url'] ?? null,
            'failure_message' => $underReview ? CheckoutIdempotencyService::PAYMENT_UNDER_REVIEW_MESSAGE : null,
            'http_status' => $underReview ? 202 : 201,
            'order_status' => $order->status->value,
        ]);

        return $this->idempotency->checkoutResponse($order, $payment);
    }

    /**
     * Libera a produção de um cartão aprovado na criação do pagamento, pela mesma regra do webhook.
     * Devolve false quando o pedido fica em revisão (divergência): pending_payment, sem nova cobrança.
     */
    private function releaseApprovedCardPayment(Order $order, GatewayPayment $payment): bool
    {
        $reasons = $this->approvalValidator->validate($order, $payment);

        if ($reasons !== []) {
            $this->approvalValidator->reportDivergence($order, $payment, $reasons, 'card');

            return false;
        }

        $previousStatus = $order->status->value;

        // Update condicional: só avança se o pedido ainda estiver pendente
        $updated = Order::whereKey($order->id)
            ->where('status', OrderStatus::PENDING_PAYMENT->value)
            ->update(['status' => OrderStatus::IN_QUEUE->value, 'paid_at' => now()]);

        $order->refresh();

        if ($updated === 0) {
            // Corrida: o webhook deste mesmo pagamento já liberou o pedido → resposta normal de aprovado
            $alreadyApproved = $order->status === OrderStatus::IN_QUEUE
                && $order->productionLogs()
                    ->where('code', ProductionLog::CODE_PAYMENT_APPROVED)
                    ->where('reference', $payment->id)
                    ->exists();

            if ($alreadyApproved) {
                return true;
            }

            if ($order->status === OrderStatus::CANCELLED) {
                $this->approvalValidator->reportApprovedAfterCancellation($order, $payment);
            } else {
                $this->approvalValidator->reportDivergence($order, $payment, ['state_conflict'], 'card');
            }

            return false;
        }

        $order->productionLogs()->create([
            'previous_status' => $previousStatus,
            'new_status' => OrderStatus::IN_QUEUE->value,
            'notes' => "Pagamento autorizado instantaneamente via cartão (ID: {$payment->id})",
            'created_at' => now(),
            'code' => ProductionLog::CODE_PAYMENT_APPROVED,
            'reference' => $payment->id,
        ]);

        event(new OrderPaid($order));
        ProcessOrderPaidNotification::dispatch($order);

        return true;
    }
}
