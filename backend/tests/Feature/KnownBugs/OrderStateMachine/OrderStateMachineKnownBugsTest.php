<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Listeners\SendOrderConfirmedNotification;
use App\Listeners\SendOrderPrintingNotification;
use App\Listeners\SendOrderShippedNotification;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPrintingStartedMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\User;
use App\Services\ProductionWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Comportamento CORRETO esperado da máquina de estados, do painel e das notificações. FALHAM hoje.
 */
#[Group('known-bugs')]
class OrderStateMachineKnownBugsTest extends CharacterizationTestCase
{
    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function assertMailTo(string $mailable, string $email): void
    {
        $matches = Mail::sent($mailable, fn ($m): bool => $m->hasTo($email))->count()
            + Mail::queued($mailable, fn ($m): bool => $m->hasTo($email))->count();

        $this->assertSame(1, $matches, "Esperado exatamente 1 {$mailable} para {$email}.");
    }

    /**
     * P1 — gêmeo de TransitionSideEffectsTest::test_objeto_desatualizado_sobrescreve_pedido_ja_cancelado
     */
    public function test_transicao_valida_contra_o_status_real_do_banco(): void
    {
        $stale = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        Order::whereKey($stale->id)->first()->forceFill(['status' => OrderStatus::CANCELLED])->save();

        try {
            app(ProductionWorkflowService::class)->transitionTo($stale, OrderStatus::PRINTING);
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertSame(OrderStatus::CANCELLED, $stale->fresh()->status);
    }

    /**
     * P2 — gêmeo de TransitionSideEffectsTest::test_dispatch_order_invalido_grava_o_codigo_mesmo_assim
     */
    public function test_dispatch_order_invalido_nao_grava_o_codigo(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        try {
            app(ProductionWorkflowService::class)->dispatchOrder($order, 'BR999');
        } catch (InvalidOrderStatusTransitionException) {
        }

        $this->assertNull($order->fresh()->carrier_tracking_code);
    }

    /**
     * P2 — gêmeo de FilamentOrderActionsTest::test_iniciar_impressao_da_fila_dispara_order_print_started_sem_listener
     */
    public function test_admin_iniciar_impressao_dispara_evento_com_listener(): void
    {
        Event::fake([OrderPrintingStartedEvent::class]);
        $this->asAdmin();
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        Livewire::test(ListOrders::class)->callTableAction('startPrinting', $order);

        Event::assertDispatched(OrderPrintingStartedEvent::class);
    }

    /**
     * P2 — gêmeo de FilamentOrderActionsTest::test_expedir_grava_codigo_log_e_order_shipped_sem_listener
     */
    public function test_admin_expedir_dispara_evento_com_listener(): void
    {
        Event::fake([OrderShippedEvent::class]);
        $this->asAdmin();
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create();

        Livewire::test(ListOrders::class)->callTableAction('markAsShipped', $order, data: ['carrier_tracking_code' => 'BR1']);

        Event::assertDispatched(OrderShippedEvent::class);
    }

    /**
     * P1 — gêmeo de NotificationListenersTest::test_listener_de_confirmacao_quebra_por_relacao_inexistente
     */
    public function test_listener_de_confirmacao_envia_email_ao_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => 'cliente@example.com']);

        (new SendOrderConfirmedNotification)->handle(new OrderPaidEvent($order, '123'));

        $this->assertMailTo(OrderConfirmedMail::class, 'cliente@example.com');
    }

    /**
     * P1 — gêmeo de NotificationListenersTest::test_listener_de_impressao_quebra_por_relacao_inexistente
     */
    public function test_listener_de_impressao_envia_email_ao_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => 'cliente@example.com']);

        (new SendOrderPrintingNotification)->handle(new OrderPrintingStartedEvent($order));

        $this->assertMailTo(OrderPrintingStartedMail::class, 'cliente@example.com');
    }

    /**
     * P1 — gêmeo de NotificationListenersTest::test_listener_de_envio_quebra_por_relacao_inexistente
     */
    public function test_listener_de_envio_envia_email_ao_cliente(): void
    {
        $order = Order::factory()->create(['customer_email' => 'cliente@example.com']);

        (new SendOrderShippedNotification)->handle(new OrderShippedEvent($order, 'BR1'));

        $this->assertMailTo(OrderShippedMail::class, 'cliente@example.com');
    }

    /**
     * P2 — gêmeo de NotificationListenersTest::test_mapa_de_listeners_registrados
     */
    public function test_confirmacao_registrada_uma_unica_vez(): void
    {
        $registrations = array_filter(
            app('events')->getRawListeners()[OrderPaidEvent::class] ?? [],
            fn ($l): bool => is_string($l) && str_starts_with($l, SendOrderConfirmedNotification::class)
        );

        $this->assertCount(1, $registrations, 'SendOrderConfirmedNotification deveria ouvir OrderPaidEvent uma única vez.');
    }
}
