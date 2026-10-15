<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Listeners\SendOrderStatusUpdatedNotification;
use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-24: com o checkout e o webhook passando pelo ProductionWorkflowService, toda liberação dispara
 * OrderStatusUpdatedEvent. Uma falha no listener desse evento não pode mudar a resposta nem o pedido.
 * Produção usa QUEUE_CONNECTION=database (config/queue.php e .env.example): o listener é ShouldQueue
 * e só roda no worker.
 */
#[Group('characterization')]
class StatusListenerFailureTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;
    use SendsMercadoPagoWebhooks;

    protected bool $fakeQueue = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);

        $this->mock(SendOrderStatusUpdatedNotification::class, function ($mock): void {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('falha no listener de status'));
        });
    }

    /** Roda os jobs da fila como o worker de produção e confirma que o do status falhou. */
    private function runQueueAndExpectStatusListenerFailure(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $failed = DB::table('failed_jobs')->get(['payload', 'exception']);
        $this->assertCount(1, $failed, 'Só o job do listener de status falha.');
        $this->assertSame(SendOrderStatusUpdatedNotification::class, json_decode($failed[0]->payload, true)['displayName']);
        $this->assertStringContainsString('falha no listener de status', $failed[0]->exception);
    }

    public function test_checkout_com_cartao_aprovado_responde_201_mesmo_com_o_listener_de_status_falhando(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('status', 'in_queue');

        $this->runQueueAndExpectStatusListenerFailure();
        $this->assertSame(OrderStatus::IN_QUEUE, Order::sole()->status);
    }

    public function test_webhook_aprovado_responde_200_mesmo_com_o_listener_de_status_falhando(): void
    {
        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->apiReturnsPayment(123, 'approved', $order);

        $this->postSignedWebhook('123')->assertOk();

        $this->runQueueAndExpectStatusListenerFailure();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
    }
}
