<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Jobs\ProcessOrderPaidNotification;
use App\Listeners\SendOrderConfirmedNotification;
use App\Listeners\SendOrderPaidNotification;
use App\Listeners\SendOrderPrintingNotification;
use App\Listeners\SendOrderShippedNotification;
use App\Listeners\SendOrderStatusUpdatedNotification;
use App\Models\Order;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CapturesLogs;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * Listeners de notificação e o que acontece quando eles rodam de verdade.
 * Esta classe NÃO usa Queue::fake(): os listeners ShouldQueue executam (sync) ou são gravados (database).
 */
#[Group('characterization')]
class NotificationListenersTest extends CharacterizationTestCase
{
    use CapturesLogs;
    use SendsMercadoPagoWebhooks;

    protected bool $fakeQueue = false;

    /**
     * @return array<string, array<int, string>>
     */
    private function appListeners(): array
    {
        $out = [];
        foreach (app('events')->getRawListeners() as $event => $listeners) {
            if (str_starts_with($event, 'App\\')) {
                $out[$event] = array_map(fn ($l): string => is_string($l) ? $l : (is_array($l) ? implode('@', $l) : 'closure'), $listeners);
            }
        }
        ksort($out);

        return $out;
    }

    public function test_mapa_de_listeners_registrados(): void
    {
        // BUG CONHECIDO: listeners registrados duas vezes (Event::listen no AppServiceProvider + descoberta automática) — ver teste gêmeo em KnownBugs
        $this->assertSame([
            OrderPaidEvent::class => [
                SendOrderConfirmedNotification::class,
                SendOrderConfirmedNotification::class.'@handle',
                SendOrderPaidNotification::class.'@handle',
            ],
            OrderPrintingStartedEvent::class => [
                SendOrderPrintingNotification::class,
                SendOrderPrintingNotification::class.'@handle',
            ],
            OrderShippedEvent::class => [
                SendOrderShippedNotification::class,
                SendOrderShippedNotification::class.'@handle',
            ],
            OrderStatusUpdatedEvent::class => [
                SendOrderStatusUpdatedNotification::class.'@handle',
            ],
        ], $this->appListeners());
        // OrderPaid, OrderPrintStarted e OrderShipped (usados por checkout e Filament) não têm listener.
    }

    public function test_listener_de_confirmacao_quebra_por_relacao_inexistente(): void
    {
        // BUG CONHECIDO: listeners usam relações customer/orderItems que não existem em Order — ver teste gêmeo em KnownBugs
        $order = Order::factory()->create();

        $this->expectException(RelationNotFoundException::class);
        $this->expectExceptionMessage('Call to undefined relationship [customer] on model [App\Models\Order].');

        (new SendOrderConfirmedNotification)->handle(new OrderPaidEvent($order, '123'));
    }

    public function test_listener_de_impressao_quebra_por_relacao_inexistente(): void
    {
        // BUG CONHECIDO: listeners usam relações customer/orderItems que não existem em Order — ver teste gêmeo em KnownBugs
        $this->expectException(RelationNotFoundException::class);

        (new SendOrderPrintingNotification)->handle(new OrderPrintingStartedEvent(Order::factory()->create()));
    }

    public function test_listener_de_envio_quebra_por_relacao_inexistente(): void
    {
        // BUG CONHECIDO: listeners usam relações customer/orderItems que não existem em Order — ver teste gêmeo em KnownBugs
        $this->expectException(RelationNotFoundException::class);

        (new SendOrderShippedNotification)->handle(new OrderShippedEvent(Order::factory()->create(), 'BR1'));
    }

    public function test_listeners_que_so_logam_funcionam(): void
    {
        $order = Order::factory()->create();
        $this->captureLogs();

        (new SendOrderPaidNotification)->handle(new OrderPaidEvent($order, '123'));
        (new SendOrderStatusUpdatedNotification)->handle(new OrderStatusUpdatedEvent($order, OrderStatus::IN_QUEUE, OrderStatus::PRINTING));
        (new ProcessOrderPaidNotification($order))->handle();

        $this->assertCount(3, $this->logsAtLevels(['info']));
        // PERGUNTA: ProcessOrderPaidNotification loga 'total' => $order->total_in_cents (atributo inexistente → null).
        $jobLog = $this->logsAtLevels(['info'], 'Processando notificações')[0];
        $this->assertNull($jobLog->context['total']);
        Mail::assertNothingOutgoing();
    }

    public function test_webhook_aprovado_com_fila_sync_falha_com_500_e_desfaz_o_pagamento(): void
    {
        // Com QUEUE_CONNECTION=sync, o listener quebrado roda dentro da transação do webhook.
        config(['queue.default' => 'sync']);
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Erro interno ao processar transação de webhook.']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
    }

    public function test_webhook_aprovado_com_fila_database_enfileira_tres_listeners(): void
    {
        // QUEUE_CONNECTION=database (valor do .env de desenvolvimento): o webhook responde 200
        // e grava 3 jobs — 2× SendOrderConfirmedNotification (registro duplicado) + 1× SendOrderPaidNotification.
        config(['queue.default' => 'database']);
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $jobs = DB::table('jobs')->pluck('payload')->map(fn ($p) => json_decode($p, true)['displayName'])->sort()->values()->all();
        $this->assertSame([
            SendOrderConfirmedNotification::class,
            SendOrderConfirmedNotification::class,
            SendOrderPaidNotification::class,
        ], $jobs);
    }
}
