<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\CheckoutOrderDTO;
use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Exceptions\ShippingPriceChangedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Jobs\ProcessOrderPaidNotification;
use App\Models\Order;
use App\Services\CheckoutIdempotencyService;
use App\Services\MercadoPagoService;
use App\Services\OrderCreationService;
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
                'code'                => 'shipping_price_changed',
                'message'             => $e->getMessage(),
                'shipping_service'    => $e->serverOption->serviceCode,
                'shipping_cost_cents' => $e->serverOption->priceInCents,
                'shipping_options'    => array_map(fn ($option) => $option->toArray(), $e->options),
            ], 409);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::warning("Validação de catálogo falhou no checkout: " . $e->getMessage());

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

            $isApproved = ($paymentResult['status'] === 'approved');

            // 4. Atualização isolada do status com log de auditoria
            if ($isApproved) {
                $previousStatus = $order->status->value;
                $order->status  = OrderStatus::IN_QUEUE;
                $order->paid_at = now();
                $order->save();

                $order->productionLogs()->create([
                    'previous_status' => $previousStatus,
                    'new_status'      => OrderStatus::IN_QUEUE->value,
                    'notes'           => "Pagamento autorizado instantaneamente via cartão (ID: {$paymentResult['payment_id']})",
                    'created_at'      => now(),
                ]);

                event(new OrderPaid($order));
                ProcessOrderPaidNotification::dispatch($order);
            }
        } catch (Exception $e) {
            // 5. Tratamento de Falha no Gateway e Atualização de Auditoria
            Log::error("Falha ao processar pagamento do pedido #{$order->order_number}: " . $e->getMessage());

            $previousStatus = $order->status->value;
            $order->status  = OrderStatus::CANCELLED;
            $order->save();

            $order->productionLogs()->create([
                'previous_status' => $previousStatus,
                'new_status'      => OrderStatus::CANCELLED->value,
                'notes'           => 'Cobrança não concluída no gateway: ' . $e->getMessage(),
                'created_at'      => now(),
            ]);

            // Resposta persistida: um reenvio com a mesma chave recebe exatamente esta resposta
            $payment = $order->payments()->create([
                'provider'        => 'mercadopago',
                'method'          => $orderDTO->paymentMethod,
                'status'          => 'error',
                'failure_message' => 'Não foi possível processar a cobrança. ' . $e->getMessage(),
                'http_status'     => 422,
                'order_status'    => $order->status->value,
            ]);

            return $this->idempotency->checkoutResponse($order, $payment);
        }

        // 5. Persiste o resultado do gateway e a resposta dada (base do replay idempotente)
        $payment = $order->payments()->create([
            'provider'       => 'mercadopago',
            'method'         => $orderDTO->paymentMethod,
            'external_id'    => (string) $paymentResult['payment_id'],
            'status'         => (string) $paymentResult['status'],
            'status_detail'  => $paymentResult['status_detail'] ?? null,
            'qr_code'        => $paymentResult['qr_code'] ?? null,
            'qr_code_base64' => $paymentResult['qr_code_base64'] ?? null,
            'ticket_url'     => $paymentResult['ticket_url'] ?? null,
            'http_status'    => 201,
            'order_status'   => $order->status->value,
        ]);

        return $this->idempotency->checkoutResponse($order, $payment);
    }
}
