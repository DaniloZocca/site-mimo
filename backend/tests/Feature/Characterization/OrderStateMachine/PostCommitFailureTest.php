<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderStatusUpdatedEvent;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Listeners\SendOrderStatusUpdatedNotification;
use App\Models\Order;
use App\Models\User;
use App\Services\ProductionWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CapturesLogs;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-32: com QUEUE_CONNECTION=sync os listeners rodam dentro da requisição. Uma falha DEPOIS de a mudança de status
 * estar gravada (evento, listener, e-mail) vai para um Log::error sem dados pessoais e não muda a resposta.
 * Uma falha antes ou durante a gravação continua derrubando a requisição.
 */
#[Group('characterization')]
class PostCommitFailureTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;
    use CapturesLogs;
    use SendsMercadoPagoWebhooks;

    private const CUSTOMER_EMAIL = 'cliente.m32@exemplo.test';

    private const FAILURE_LOG = 'Falha depois da mudança de status: aviso não entregue.';

    protected bool $fakeQueue = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        $this->captureLogs();
    }

    private function statusListenerThrows(): void
    {
        $this->mock(SendOrderStatusUpdatedNotification::class, function ($mock): void {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('falha no listener de status '.self::CUSTOMER_EMAIL));
        });
    }

    private function mailServerFails(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('550 5.1.1 <'.self::CUSTOMER_EMAIL.'>: Recipient address rejected'));
    }

    private function assertOneFailureLog(Order $order, string $event): void
    {
        $logs = $this->logsAtLevels(['error'], self::FAILURE_LOG);
        $this->assertCount(1, $logs);
        $this->assertSame($order->order_number, $logs[0]->context['order_number']);
        $this->assertSame($event, $logs[0]->context['event']);
        $this->assertSame(RuntimeException::class, $logs[0]->context['exception']);
        $this->assertSame(['order_number', 'event', 'listener', 'exception'], array_keys($logs[0]->context));

        foreach ($this->capturedLogs as $log) {
            $this->assertStringNotContainsString(self::CUSTOMER_EMAIL, $log->message.json_encode($log->context));
        }
    }

    private function approvedCardCheckout(): Order
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $payload['customer']['email'] = self::CUSTOMER_EMAIL;
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('status', 'in_queue');

        $order = Order::sole();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);

        return $order;
    }

    private function approvedWebhook(): Order
    {
        $order = Order::factory()->create(['total_cents' => 10000, 'customer_email' => self::CUSTOMER_EMAIL]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertOk()->assertJsonPath('order_status', 'in_queue');

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);

        return $order;
    }

    private function startPrintingInPanel(): Order
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create(['customer_email' => self::CUSTOMER_EMAIL]);

        Livewire::test(ListOrders::class)
            ->callTableAction('startPrinting', $order)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Extrusão 3D Iniciada!');

        $this->assertSame(OrderStatus::PRINTING, $order->fresh()->status);

        return $order;
    }

    public function test_listener_de_status_falhando_no_cartao_aprovado_responde_201(): void
    {
        $this->statusListenerThrows();

        $order = $this->approvedCardCheckout();

        $this->assertOneFailureLog($order, OrderStatusUpdatedEvent::class);
    }

    public function test_listener_de_status_falhando_no_webhook_aprovado_responde_200(): void
    {
        $this->statusListenerThrows();

        $order = $this->approvedWebhook();

        $this->assertOneFailureLog($order, OrderStatusUpdatedEvent::class);
    }

    public function test_listener_de_status_falhando_no_painel_mostra_sucesso(): void
    {
        $this->statusListenerThrows();

        $order = $this->startPrintingInPanel();

        $this->assertOneFailureLog($order, OrderStatusUpdatedEvent::class);
    }

    public function test_email_de_confirmacao_falhando_no_cartao_aprovado_responde_201(): void
    {
        $this->mailServerFails();

        $order = $this->approvedCardCheckout();

        $this->assertOneFailureLog($order, OrderPaidEvent::class);
    }

    public function test_email_de_confirmacao_falhando_no_webhook_aprovado_responde_200(): void
    {
        $this->mailServerFails();

        $order = $this->approvedWebhook();

        $this->assertOneFailureLog($order, OrderPaidEvent::class);
    }

    public function test_email_de_impressao_falhando_no_painel_mostra_sucesso(): void
    {
        $this->mailServerFails();

        $order = $this->startPrintingInPanel();

        $this->assertOneFailureLog($order, OrderPrintingStartedEvent::class);
    }

    public function test_falha_durante_a_gravacao_do_status_nao_e_engolida(): void
    {
        $this->mock(ProductionWorkflowService::class, function ($mock): void {
            $mock->shouldReceive('transitionFrom')->andThrow(new RuntimeException('banco recusou o UPDATE'));
        });
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertStatus(500);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame([], $this->logsAtLevels(['error'], self::FAILURE_LOG));
    }
}
