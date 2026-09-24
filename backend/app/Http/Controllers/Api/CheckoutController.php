<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\CheckoutOrderDTO;
use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Jobs\ProcessOrderPaidNotification;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\OrderCreationService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
        private readonly MercadoPagoService $mercadoPagoService,
    ) {}

    /**
     * Orquestração segura do Checkout com Zero-Trust Pricing e Atomicidade.
     */
    public function __invoke(CheckoutRequest $request): JsonResponse
    {
        // 1. Converte Request Validado em DTO Imutável (PHP 8.2+)
        $orderDTO = CheckoutOrderDTO::fromRequest($request);

        // 2. Criação Atômica do Pedido no PostgreSQL com Zero-Trust Pricing
        // REGRA DE OURO: A transação DB commita ANTES de qualquer chamada de rede externa
        try {
            $order = $this->orderCreationService->createOrder($orderDTO);
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

            return response()->json([
                'message'         => 'Pedido gerado com sucesso!',
                'order_id'        => $order->id,
                'order_number'    => $order->order_number,
                'tracking_token'  => $order->tracking_token,
                'status'          => $order->status->value,
                'status_label'    => $order->status->getLabel(),
                'total_cents'     => $order->total_cents,
                'total_formatted' => 'R$ ' . number_format($order->total_cents / 100, 2, ',', '.'),
                'payment'         => [
                    'method'          => $orderDTO->paymentMethod,
                    'payment_id'      => $paymentResult['payment_id'],
                    'status'          => $paymentResult['status'],
                    'status_detail'   => $paymentResult['status_detail'] ?? null,
                    'qr_code'         => $paymentResult['qr_code'] ?? null,
                    'qr_code_base64'  => $paymentResult['qr_code_base64'] ?? null,
                    'ticket_url'      => $paymentResult['ticket_url'] ?? null,
                    'idempotency_key' => $paymentResult['idempotency_key'],
                ],
            ], 201);

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

            return response()->json([
                'message'      => 'Não foi possível processar a cobrança. ' . $e->getMessage(),
                'order_number' => $order->order_number,
            ], 422);
        }
    }
}
