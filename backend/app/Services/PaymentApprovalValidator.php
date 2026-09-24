<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GatewayPayment;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductionLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regra única para liberar a produção de um pedido a partir de um pagamento do Mercado Pago.
 * Usada pelo webhook (pagamento vindo do GET) e pela aprovação síncrona do cartão (resposta do POST).
 *
 * Divergência nunca cancela o pedido: ele fica em pending_payment para revisão humana.
 */
class PaymentApprovalValidator
{
    public const NOT_APPROVED = 'not_approved';

    public const REFERENCE_MISMATCH = 'reference_mismatch';

    public const PAYMENT_ID_MISMATCH = 'payment_id_mismatch';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const AMOUNT_INVALID = 'amount_invalid';

    public const LIVE_MODE_MISMATCH = 'live_mode_mismatch';

    /**
     * Motivos pelos quais o pagamento NÃO libera o pedido. Vazio = liberar.
     *
     * @return array<int, string>
     */
    public function validate(Order $order, GatewayPayment $payment): array
    {
        $reasons = [];

        if ($payment->status !== 'approved') {
            $reasons[] = self::NOT_APPROVED;
        }

        if ($payment->externalReference !== (string) $order->order_number) {
            $reasons[] = self::REFERENCE_MISMATCH;
        }

        // Só confere o id quando o checkout já gravou algum; sem id gravado a checagem é pulada.
        $knownIds = $order->payments()->whereNotNull('external_id')->pluck('external_id')->map(fn ($id): string => (string) $id);
        if ($knownIds->isNotEmpty() && ! $knownIds->contains($payment->id)) {
            $reasons[] = self::PAYMENT_ID_MISMATCH;
        }

        if ($payment->currencyId !== 'BRL') {
            $reasons[] = self::CURRENCY_MISMATCH;
        }

        if ($payment->amountCents === null) {
            $reasons[] = self::AMOUNT_INVALID;
        } elseif ($payment->amountCents !== (int) $order->total_cents) {
            $reasons[] = self::AMOUNT_MISMATCH;
        }

        // Em produção, pagamento de teste (sandbox) não libera pedido.
        if (app()->isProduction() && $payment->liveMode !== true) {
            $reasons[] = self::LIVE_MODE_MISMATCH;
        }

        return $reasons;
    }

    /**
     * Pagamento aprovado com divergência: alerta crítico + registro interno para revisão.
     *
     * @param  array<int, string>  $reasons
     */
    public function reportDivergence(Order $order, GatewayPayment $payment, array $reasons, string $channel): void
    {
        Log::critical("🚨 [Pagamento em revisão] Pagamento aprovado com divergência no pedido #{$order->order_number}: produção não liberada.", [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'reasons' => $reasons,
            'channel' => $channel,
        ]);

        $this->recordInternalLog(
            $order,
            ProductionLog::CODE_PAYMENT_REVIEW,
            $payment->id,
            OrderStatus::PENDING_PAYMENT->value,
            '[Sistema] Pagamento '.$payment->id.' aprovado com divergência ('.implode(', ', $reasons).') — revisão manual; produção não liberada.'
        );
    }

    /**
     * Dinheiro recebido para pedido cancelado: alerta crítico + registro interno. Não reabre o pedido.
     */
    public function reportApprovedAfterCancellation(Order $order, GatewayPayment $payment): void
    {
        Log::critical("🚨 [Pagamento após cancelamento] Pagamento aprovado para o pedido cancelado #{$order->order_number}: verificar estorno.", [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
        ]);

        $this->recordInternalLog(
            $order,
            ProductionLog::CODE_PAYMENT_AFTER_CANCELLATION,
            $payment->id,
            OrderStatus::CANCELLED->value,
            '[Sistema] Pagamento '.$payment->id.' recebido após cancelamento — verificar estorno.'
        );
    }

    /**
     * Registro interno idempotente por (pedido, code, reference): notificações repetidas não duplicam.
     */
    private function recordInternalLog(Order $order, string $code, string $reference, string $status, string $notes): void
    {
        $exists = ProductionLog::where('order_id', $order->id)
            ->where('code', $code)
            ->where('reference', $reference)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            // Savepoint: no PostgreSQL, a violação sem savepoint abortaria a transação externa (webhook)
            DB::transaction(fn () => $order->productionLogs()->create([
                'previous_status' => $status,
                'new_status' => $status,
                'notes' => $notes,
                'created_at' => now(),
                'is_internal' => true,
                'code' => $code,
                'reference' => $reference,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Corrida entre webhook e checkout: o outro já gravou o mesmo registro (índice único)
        }
    }
}
