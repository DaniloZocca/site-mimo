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
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPrintingStartedMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
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

    private const CUSTOMER_EMAIL = 'cliente@example.com';

    private function assertMailTo(string $mailable, string $email): void
    {
        $matches = Mail::sent($mailable, fn ($m): bool => $m->hasTo($email))->count()
            + Mail::queued($mailable, fn ($m): bool => $m->hasTo($email))->count();

        $this->assertSame(1, $matches, "Esperado exatamente 1 {$mailable} para {$email}.");
    }

    private function assertNoLogContains(string $needle): void
    {
        $this->assertNotEmpty($this->capturedLogs, 'Nenhum log capturado: o teste não exercitou o caminho esperado.');

        foreach ($this->capturedLogs as $log) {
            /** @var MessageLogged $log */
            $this->assertStringNotContainsString($needle, $log->message.' '.json_encode($log->context));
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function appListeners(): array
    {
        $out = [];
        foreach (app('events')->getRawListeners() as $event => $listeners) {
            if (str_starts_with($event, 'App\\')) {
                $out[$event] = array_map(fn ($l): string => is_string($l) ? $l : (is_array($l) ? implode('@', $l) : 'closure'), $listeners);
                // A descoberta automática segue a ordem do sistema de arquivos (varia entre máquinas); o que importa é cada listener aparecer uma vez.
                sort($out[$event]);
            }
        }
        ksort($out);

        return $out;
    }

    public function test_mapa_de_listeners_registrados(): void
    {
        // Cada listener ouve seu evento uma vez só (descoberta automática do Laravel 11, sem Event::listen duplicado).
        $this->assertSame([
            OrderPaidEvent::class => [
                SendOrderConfirmedNotification::class.'@handle',
                SendOrderPaidNotification::class.'@handle',
            ],
            OrderPrintingStartedEvent::class => [
                SendOrderPrintingNotification::class.'@handle',
            ],
            OrderShippedEvent::class => [
                SendOrderShippedNotification::class.'@handle',
            ],
            OrderStatusUpdatedEvent::class => [
                SendOrderStatusUpdatedNotification::class.'@handle',
            ],
        ], $this->appListeners());
        // OrderPaid, OrderPrintStarted e OrderShipped (usados por checkout e Filament) não têm listener (bugs #20 e #21).
    }

    /**
     * Corrigido #23 — antes: gêmeo em KnownBugs/OrderStateMachine/OrderStateMachineKnownBugsTest.
     */
    public function test_confirmacao_registrada_uma_unica_vez(): void
    {
        $registrations = array_filter(
            app('events')->getRawListeners()[OrderPaidEvent::class] ?? [],
            fn ($l): bool => is_string($l) && str_starts_with($l, SendOrderConfirmedNotification::class)
        );

        $this->assertCount(1, $registrations, 'SendOrderConfirmedNotification deveria ouvir OrderPaidEvent uma única vez.');
    }

    /**
     * Corrigido #13 — antes: gêmeo em KnownBugs/OrderStateMachine/OrderStateMachineKnownBugsTest.
     */
    public function test_listener_de_confirmacao_envia_email_ao_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => self::CUSTOMER_EMAIL]);

        (new SendOrderConfirmedNotification)->handle(new OrderPaidEvent($order, '123'));

        $this->assertMailTo(OrderConfirmedMail::class, self::CUSTOMER_EMAIL);
    }

    /**
     * Corrigido #13 — antes: gêmeo em KnownBugs/OrderStateMachine/OrderStateMachineKnownBugsTest.
     */
    public function test_listener_de_impressao_envia_email_ao_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => self::CUSTOMER_EMAIL]);

        (new SendOrderPrintingNotification)->handle(new OrderPrintingStartedEvent($order));

        $this->assertMailTo(OrderPrintingStartedMail::class, self::CUSTOMER_EMAIL);
    }

    /**
     * Corrigido #13 — antes: gêmeo em KnownBugs/OrderStateMachine/OrderStateMachineKnownBugsTest.
     */
    public function test_listener_de_envio_envia_email_ao_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => self::CUSTOMER_EMAIL]);

        (new SendOrderShippedNotification)->handle(new OrderShippedEvent($order, 'BR1'));

        $this->assertMailTo(OrderShippedMail::class, self::CUSTOMER_EMAIL);
    }

    public function test_listeners_enviam_o_email_na_hora_sem_enfileirar_de_novo(): void
    {
        // O próprio listener é o job da fila; se o Mailable fosse enfileirado de novo, uma falha de SMTP
        // não passaria pelos tries/failed() do listener.
        $order = Order::factory()->create(['customer_email' => self::CUSTOMER_EMAIL]);

        (new SendOrderConfirmedNotification)->handle(new OrderPaidEvent($order, '123'));
        (new SendOrderPrintingNotification)->handle(new OrderPrintingStartedEvent($order));
        (new SendOrderShippedNotification)->handle(new OrderShippedEvent($order, 'BR1'));

        Mail::assertSentCount(3);
        Mail::assertNothingQueued();
    }

    public function test_listeners_de_email_so_rodam_depois_do_commit(): void
    {
        foreach ([SendOrderConfirmedNotification::class, SendOrderPrintingNotification::class, SendOrderShippedNotification::class] as $listener) {
            $this->assertInstanceOf(ShouldQueueAfterCommit::class, new $listener);
        }
    }

    public function test_emails_renderizam_com_primeiro_nome_e_itens_do_pedido(): void
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create([
            'customer_name' => 'Fernanda Souza Lima',
            'carrier_tracking_code' => 'BR123456789BR',
        ]);
        OrderItem::factory()->for($order)->create(['product_title_snapshot' => 'Dragão Articulado']);

        foreach ([new OrderConfirmedMail($order), new OrderPrintingStartedMail($order), new OrderShippedMail($order)] as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Fernanda!', $html);
            $this->assertStringNotContainsString('Souza', $html);
            $this->assertStringContainsString("#{$order->order_number}", $html);
        }

        $this->assertStringContainsString('Dragão Articulado', (new OrderConfirmedMail($order))->render());
        $this->assertStringContainsString('BR123456789BR', (new OrderShippedMail($order))->render());
    }

    public function test_email_de_confirmacao_pix_mostra_subtotal_desconto_frete_e_total_do_snapshot(): void
    {
        // Peças 2×5000 + 1×3990 = 13990; PIX 10% = round(1399) = 1399; frete 2150; total = 13990 − 1399 + 2150 = 14741
        $order = Order::factory()->create(['shipping_cost_cents' => 2150, 'total_cents' => 14741]);
        OrderItem::factory()->for($order)->create(['unit_price_cents' => 5000, 'quantity' => 2]);
        OrderItem::factory()->for($order)->create(['unit_price_cents' => 3990, 'quantity' => 1]);

        $mail = new OrderConfirmedMail($order);
        $with = $mail->content()->with;

        $this->assertSame('R$ 139,90', $with['subtotalFormatted']);
        $this->assertSame('− R$ 13,99', $with['discountFormatted']);
        $this->assertSame('R$ 21,50', $with['shippingFormatted']);
        $this->assertSame('R$ 147,41', $with['totalAmountFormatted']);

        $html = $mail->render();
        $this->assertStringContainsString('Desconto PIX (10%):', $html);
        $this->assertStringContainsString('− R$ 13,99', $html);
        $this->assertStringContainsString('R$ 147,41', $html);
    }

    public function test_email_de_confirmacao_cartao_nao_mostra_linha_de_desconto(): void
    {
        // Cartão: total = peças + frete = 123456 + 1500
        $order = Order::factory()->create(['shipping_cost_cents' => 1500, 'total_cents' => 124956]);
        OrderItem::factory()->for($order)->create(['unit_price_cents' => 123456, 'quantity' => 1]);

        $mail = new OrderConfirmedMail($order);
        $with = $mail->content()->with;

        $this->assertSame('R$ 1.234,56', $with['subtotalFormatted']);
        $this->assertNull($with['discountFormatted']);
        $this->assertSame('R$ 15,00', $with['shippingFormatted']);
        $this->assertSame('R$ 1.249,56', $with['totalAmountFormatted']);
        $this->assertStringNotContainsString('Desconto PIX', $mail->render());
    }

    public function test_listeners_e_job_nao_logam_o_email_do_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => self::CUSTOMER_EMAIL]);
        $this->captureLogs();

        (new SendOrderConfirmedNotification)->handle(new OrderPaidEvent($order, '123'));
        (new SendOrderPrintingNotification)->handle(new OrderPrintingStartedEvent($order));
        (new SendOrderShippedNotification)->handle(new OrderShippedEvent($order, 'BR1'));
        (new SendOrderPaidNotification)->handle(new OrderPaidEvent($order, '123'));
        (new SendOrderStatusUpdatedNotification)->handle(new OrderStatusUpdatedEvent($order, OrderStatus::IN_QUEUE, OrderStatus::PRINTING));
        (new ProcessOrderPaidNotification($order))->handle();

        $this->assertCount(6, $this->logsAtLevels(['info']));
        $this->assertNoLogContains(self::CUSTOMER_EMAIL);
    }

    public function test_falha_definitiva_do_envio_nao_loga_email_nem_mensagem_da_excecao(): void
    {
        $order = Order::factory()->create(['customer_email' => self::CUSTOMER_EMAIL]);
        $smtpError = new RuntimeException('550 5.1.1 <'.self::CUSTOMER_EMAIL.'>: Recipient address rejected');
        $this->captureLogs();

        (new SendOrderConfirmedNotification)->failed(new OrderPaidEvent($order, '123'), $smtpError);
        (new SendOrderPrintingNotification)->failed(new OrderPrintingStartedEvent($order), $smtpError);
        (new SendOrderShippedNotification)->failed(new OrderShippedEvent($order, 'BR1'), $smtpError);

        $errors = $this->logsAtLevels(['error']);
        $this->assertCount(3, $errors);
        $this->assertSame(RuntimeException::class, $errors[0]->context['exception']);
        $this->assertNoLogContains(self::CUSTOMER_EMAIL);
        $this->assertNoLogContains('Recipient address rejected');
    }

    public function test_listeners_que_so_logam_nao_enviam_email(): void
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

    public function test_webhook_aprovado_com_fila_sync_confirma_o_pagamento_e_envia_um_email_ao_cliente(): void
    {
        config(['queue.default' => 'sync']);
        $order = Order::factory()->create(['total_cents' => 10000, 'customer_email' => self::CUSTOMER_EMAIL]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(1, $order->productionLogs()->count());
        $this->assertMailTo(OrderConfirmedMail::class, self::CUSTOMER_EMAIL);
    }

    public function test_webhook_com_fila_sync_e_falha_no_envio_nao_desfaz_o_pagamento(): void
    {
        // O listener roda depois do commit: o erro do servidor de e-mail não desfaz a confirmação.
        // O webhook ainda responde 500 (o erro sobe até o catch do controller); o reenvio do Mercado Pago
        // cai no caminho idempotente. Em produção a fila é database e o erro fica no job.
        config(['queue.default' => 'sync']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('servidor de e-mail fora do ar'));
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertStatus(500);

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(1, $order->productionLogs()->count());
    }

    public function test_webhook_aprovado_com_fila_database_enfileira_um_job_por_listener(): void
    {
        // QUEUE_CONNECTION=database (valor de produção): o webhook responde 200 e grava 1 job por listener.
        config(['queue.default' => 'database']);
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $jobs = DB::table('jobs')->pluck('payload')->map(fn ($p) => json_decode($p, true)['displayName'])->sort()->values()->all();
        $this->assertSame([
            SendOrderConfirmedNotification::class,
            SendOrderPaidNotification::class,
        ], $jobs);
    }
}
