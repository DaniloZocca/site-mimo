<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\OrderStatus;
use App\Jobs\ProcessOrderPaidNotification;
use App\Listeners\SendOrderConfirmedNotification;
use App\Listeners\SendOrderPaidNotification;
use App\Listeners\SendOrderStatusUpdatedNotification;
use App\Models\Order;
use App\Models\ProductionLog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concurrency\Support\ConcurrencyTestCase;

/**
 * M-45: webhook do Mercado Pago, cartão e painel disputando o mesmo pedido, cada um no seu processo.
 */
#[Group('concurrency')]
class WebhookConcurrencyTest extends ConcurrencyTestCase
{
    private const PAYMENT_ID = 777001;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = Order::factory()->create(['total_cents' => 10000]);
        $this->order->payments()->create([
            'provider' => 'mercadopago',
            'method' => 'pix',
            'external_id' => (string) self::PAYMENT_ID,
            'status' => 'pending',
            'http_status' => 201,
            'order_status' => OrderStatus::PENDING_PAYMENT->value,
        ]);
    }

    public function test_dois_webhooks_aprovados_ao_mesmo_tempo_liberam_o_pedido_uma_vez_so(): void
    {
        $this->holdOrderLock();
        $first = $this->startWorker('webhook', $this->approved('req-1'));
        $second = $this->startWorker('webhook', $this->approved('req-2'));
        $this->assertWaitingForLock($first, $second);
        DB::commit();

        $results = [$this->resultOf($first), $this->resultOf($second)];

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $this->assertNotNull($this->order->paid_at);
        $this->assertSame(1, $this->approvalLogs());
        $this->assertOneSetOfPaidNotifications();
    }

    public function test_webhook_aprovado_e_cancelar_do_painel_juntos_nunca_deixam_pedido_pago_e_cancelado(): void
    {
        $this->holdOrderLock();
        $webhook = $this->startWorker('webhook', $this->approved('req-1'));
        $cancel = $this->startWorker('cancel', ['order_id' => $this->order->id]);
        $this->assertWaitingForLock($webhook, $cancel);
        DB::commit();

        $webhookResult = $this->resultOf($webhook);
        $cancelResult = $this->resultOf($cancel);
        $status = $this->order->refresh()->status;

        $this->assertSame(200, $webhookResult['status']);
        $this->assertContains($status, [OrderStatus::IN_QUEUE, OrderStatus::CANCELLED]);

        if ($status === OrderStatus::IN_QUEUE) {
            // O webhook chegou primeiro: o cancelar recebe false (tela desatualizada) e não grava nada
            $this->assertFalse($cancelResult['applied']);
            $this->assertSame(1, $this->approvalLogs());
            $this->assertSame(0, $this->statusLogsTo(OrderStatus::CANCELLED));
        } else {
            // O cancelar chegou primeiro: o pagamento vira registro interno para estorno, sem reabrir
            $this->assertTrue($cancelResult['applied']);
            $this->assertSame(0, $this->approvalLogs());
            $this->assertSame(1, ProductionLog::where('order_id', $this->order->id)->where('code', ProductionLog::CODE_PAYMENT_AFTER_CANCELLATION)->count());
            $this->assertNull($this->order->paid_at);
        }
    }

    public function test_cartao_aprovado_e_webhook_do_mesmo_pagamento_juntos_liberam_uma_vez_so(): void
    {
        $this->holdOrderLock();
        $card = $this->startWorker('card_release', ['order_id' => $this->order->id, 'payment_id' => self::PAYMENT_ID, 'amount_cents' => 10000]);
        $webhook = $this->startWorker('webhook', $this->approved('req-1'));
        $this->assertWaitingForLock($card, $webhook);
        DB::commit();

        $cardResult = $this->resultOf($card);
        $webhookResult = $this->resultOf($webhook);

        $this->assertTrue($cardResult['released']);
        $this->assertSame(200, $webhookResult['status']);
        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $this->assertSame(1, $this->approvalLogs());
        $this->assertSame(0, ProductionLog::where('order_id', $this->order->id)->where('code', ProductionLog::CODE_PAYMENT_REVIEW)->count());
        $this->assertOneSetOfPaidNotifications();
        $this->assertLessThanOrEqual(1, DB::table('jobs')->where('payload', 'like', '%ProcessOrderPaidNotification%')->count());
    }

    public function test_dois_webhooks_com_valor_divergente_gravam_um_registro_de_revisao_so(): void
    {
        $diverging = ['amount_cents' => 9000] + $this->approved('req-1');

        $this->holdOrderLock();
        $first = $this->startWorker('webhook', $diverging);
        $second = $this->startWorker('webhook', ['request_id' => 'req-2'] + $diverging);
        $this->assertWaitingForLock($first, $second);
        DB::commit();

        $this->assertSame([200, 200], [$this->resultOf($first)['status'], $this->resultOf($second)['status']]);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertSame(1, ProductionLog::where('order_id', $this->order->id)->where('code', ProductionLog::CODE_PAYMENT_REVIEW)->count());
    }

    /**
     * Acréscimo do Zocca (A1): pedido travado por outra conexão → resposta tratada em menos de 15 s, sem gravar
     * nada; o reenvio do Mercado Pago, já sem a trava, processa normalmente.
     */
    public function test_webhook_com_pedido_travado_responde_em_menos_de_15_s_e_o_reenvio_processa(): void
    {
        $this->holdOrderLock();
        $blocked = $this->runWorker('webhook', $this->approved('req-1'));
        DB::rollBack();

        $this->assertSame(503, $blocked['status']);
        $this->assertSame(['message' => 'Pedido em processamento. Tente novamente.'], $blocked['body']);
        $this->assertGreaterThanOrEqual(9_000, $blocked['ms']);
        $this->assertLessThan(15_000, $blocked['ms']);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->refresh()->status);
        $this->assertSame(0, ProductionLog::where('order_id', $this->order->id)->count());

        $retry = $this->runWorker('webhook', $this->approved('req-2'));

        $this->assertSame(200, $retry['status']);
        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
        $this->assertSame(1, $this->approvalLogs());
        $this->assertOneSetOfPaidNotifications();
    }

    /**
     * O teste segura a linha do pedido (SELECT ... FOR UPDATE) numa transação aberta.
     */
    private function holdOrderLock(): void
    {
        DB::beginTransaction();
        Order::whereKey($this->order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function approved(string $requestId): array
    {
        return [
            'payment_id' => self::PAYMENT_ID,
            'status' => 'approved',
            'order_number' => $this->order->order_number,
            'amount_cents' => 10000,
            'request_id' => $requestId,
        ];
    }

    private function approvalLogs(): int
    {
        return ProductionLog::where('order_id', $this->order->id)->where('code', ProductionLog::CODE_PAYMENT_APPROVED)->count();
    }

    private function statusLogsTo(OrderStatus $status): int
    {
        return ProductionLog::where('order_id', $this->order->id)->where('new_status', $status->value)->count();
    }

    /**
     * Um pagamento aprovado enfileira um aviso de cada (e-mail de confirmação, aviso de pago, mudança de status).
     */
    private function assertOneSetOfPaidNotifications(): void
    {
        // ProcessOrderPaidNotification só é enfileirado quando quem libera é o cartão; os avisos abaixo valem nos dois caminhos
        $listeners = DB::table('jobs')->pluck('payload')
            ->map(fn (string $payload): string => (string) json_decode($payload, true)['displayName'])
            ->reject(fn (string $name): bool => $name === ProcessOrderPaidNotification::class)
            ->countBy()
            ->sortKeys()
            ->all();

        $this->assertSame([
            SendOrderConfirmedNotification::class => 1,
            SendOrderPaidNotification::class => 1,
            SendOrderStatusUpdatedNotification::class => 1,
        ], $listeners);
    }
}
