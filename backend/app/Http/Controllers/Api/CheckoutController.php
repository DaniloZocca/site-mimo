<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\CheckoutOrderDTO;
use App\DTOs\GatewayPayment;
use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Exceptions\ShippingPriceChangedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Jobs\ProcessOrderPaidNotification;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Services\AfterCommitDispatcher;
use App\Services\CheckoutIdempotencyService;
use App\Services\MercadoPagoService;
use App\Services\OrderCreationService;
use App\Services\PaymentApprovalValidator;
use App\Services\ProductionWorkflowService;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    /** @var array<string, string> status_detail do Mercado Pago → mensagem ao cliente */
    private const CARD_REJECTION_MESSAGES = [
        'cc_rejected_insufficient_amount' => 'Saldo insuficiente no cartão de crédito.',
        'cc_rejected_bad_filled_security_code' => 'Código de segurança (CVV) incorreto.',
        'cc_rejected_bad_filled_date' => 'Data de validade do cartão incorreta.',
        'cc_rejected_bad_filled_other' => 'Dados do cartão incorretos. Verifique e tente novamente.',
        'cc_rejected_call_for_authorize' => 'Pagamento não autorizado pelo banco emissor. Entre em contato com a operadora do seu cartão.',
        'cc_rejected_card_disabled' => 'O cartão informado está desabilitado ou bloqueado.',
        'cc_rejected_duplicated_payment' => 'Pagamento duplicado identificado para esta encomenda.',
        'cc_rejected_high_risk' => 'Transação recusada por políticas de segurança do banco emissor.',
        'cc_rejected_max_attempts' => 'Limite de tentativas excedido no cartão. Tente novamente mais tarde ou use o PIX.',
        'cc_rejected_blacklist' => 'Não foi possível processar o pagamento com este cartão.',
    ];

    public function __construct(
        private readonly OrderCreationService $orderCreationService,
        private readonly MercadoPagoService $mercadoPagoService,
        private readonly CheckoutIdempotencyService $idempotency,
        private readonly PaymentApprovalValidator $approvalValidator,
        private readonly ProductionWorkflowService $workflow,
        private readonly AfterCommitDispatcher $afterCommit,
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
            // 5. Falha no gateway: o pedido segue pending_payment. Num timeout a cobrança pode ter sido criada
            // no Mercado Pago; o webhook aprovado leva o pedido para revisão (PaymentApprovalValidator), porque
            // o cliente pode ter pago de novo em outra tentativa. O detalhe técnico fica só no log do servidor.
            Log::error("Falha ao processar pagamento do pedido #{$order->order_number}: ".$e->getMessage());

            // Resposta persistida: um reenvio com a mesma chave recebe exatamente esta resposta
            $payment = $order->payments()->create([
                'provider' => 'mercadopago',
                'method' => $orderDTO->paymentMethod,
                'status' => CheckoutIdempotencyService::PAYMENT_STATUS_ERROR,
                'failure_message' => $this->gatewayErrorMessage($order, $orderDTO->paymentMethod),
                'http_status' => 502,
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

        // Cartão recusado: 422 com mensagem amigável. O pedido segue pending_payment e o webhook
        // `rejected` o cancela; uma nova tentativa do cliente usa outra Idempotency-Key (novo pedido).
        $rejected = $paymentResult['status'] === 'rejected';

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
            'failure_message' => match (true) {
                $underReview => CheckoutIdempotencyService::PAYMENT_UNDER_REVIEW_MESSAGE,
                $rejected => self::cardRejectionMessage($paymentResult['status_detail'] ?? null),
                default => null,
            },
            'http_status' => match (true) {
                $underReview => 202,
                $rejected => 422,
                default => 201,
            },
            'order_status' => $order->status->value,
        ]);

        return $this->idempotency->checkoutResponse($order, $payment);
    }

    /**
     * Mensagem ao cliente quando a chamada ao gateway falha (timeout, erro da API), sem detalhe técnico.
     */
    private function gatewayErrorMessage(Order $order, string $paymentMethod): string
    {
        if ($paymentMethod === 'pix') {
            return 'Não conseguimos gerar o seu PIX agora 😕 Nenhuma cobrança foi feita. Tente de novo em alguns instantes.';
        }

        return "Não conseguimos confirmar seu pagamento agora 😕 Seu pedido #{$order->order_number} ficou guardado. "
            .'Se a cobrança aparecer no seu cartão, não pague de novo: vamos conferir e confirmar seu pedido pelo e-mail. '
            .'Se nada for cobrado, é só tentar de novo.';
    }

    /**
     * Texto amigável da recusa do cartão (mesmos textos de CARD_ERROR_MESSAGES em src/services/mercadoPago.js).
     * O status_detail técnico (cc_rejected_*) nunca vai para a mensagem.
     */
    private static function cardRejectionMessage(?string $statusDetail): string
    {
        return self::CARD_REJECTION_MESSAGES[(string) $statusDetail]
            ?? 'O pagamento com cartão não pôde ser autorizado. Verifique os dados ou utilize o PIX com aprovação imediata.';
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

        // Regra 5: pelo serviço, com origem esperada (só avança se o pedido ainda estiver pendente)
        $updated = $this->workflow->transitionFrom(
            $order,
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::IN_QUEUE,
            "Pagamento autorizado instantaneamente via cartão (ID: {$payment->id})",
            extra: ['paid_at' => now()],
            log: ['code' => ProductionLog::CODE_PAYMENT_APPROVED, 'reference' => $payment->id],
        );

        if (! $updated) {
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

        // Depois da gravação: falha de aviso/e-mail vai para o log e não muda a resposta (M-32)
        $this->afterCommit->event($order, new OrderPaidEvent($order, $payment->id));
        $this->afterCommit->job($order, new ProcessOrderPaidNotification($order));

        return true;
    }
}
