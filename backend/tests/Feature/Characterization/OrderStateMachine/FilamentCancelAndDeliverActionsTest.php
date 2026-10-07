<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\OrderStateMachine;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Mail\OrderPrintingStartedMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-10: ações "Cancelar Pedido" (só pedido não pago, fora de revisão de pagamento) e "Marcar como Entregue"
 * (só expedido), e os e-mails de impressão e envio disparados pelo painel (bug #21).
 * Sem Queue::fake(): os listeners rodam na fila sync e o Mail::fake() da base registra os e-mails.
 */
#[Group('characterization')]
class FilamentCancelAndDeliverActionsTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    protected bool $fakeQueue = false;

    private const STATUS_CHANGED_MESSAGE = 'O status deste pedido mudou. Atualize a página.';

    private const CUSTOMER_EMAIL = 'cliente@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function actionsByStatus(): array
    {
        $origins = ['cancelOrder' => 'pending_payment', 'markAsDelivered' => 'shipped'];
        $cases = [];
        foreach ($origins as $action => $origin) {
            foreach (OrderStatus::cases() as $status) {
                $cases["{$action} em {$status->value}"] = [$action, $status->value, $origin];
            }
        }

        return $cases;
    }

    #[DataProvider('actionsByStatus')]
    public function test_acao_so_fica_visivel_no_status_de_origem(string $action, string $status, string $origin): void
    {
        $order = Order::factory()->status(OrderStatus::from($status))->create();
        $component = Livewire::test(ListOrders::class);

        $origin === $status
            ? $component->assertTableActionVisible($action, $order)
            : $component->assertTableActionHidden($action, $order);
    }

    public function test_cancelar_pedido_pendente_grava_status_e_log_com_motivo_sem_email(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create(['customer_email' => self::CUSTOMER_EMAIL]);

        Livewire::test(ListOrders::class)
            ->callTableAction('cancelOrder', $order, data: ['reason' => 'Cliente desistiu da compra'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Pedido Cancelado');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $log = $order->productionLogs()->sole();
        $this->assertSame(
            ['pending_payment', 'cancelled', 'Pedido cancelado pelo painel. Motivo: Cliente desistiu da compra', ProductionLog::CODE_STATUS_CHANGE, false],
            [$log->previous_status, $log->new_status, $log->notes, $log->code, $log->is_internal]
        );
        Mail::assertNothingOutgoing();
    }

    public function test_cancelar_exige_motivo(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();

        Livewire::test(ListOrders::class)
            ->callTableAction('cancelOrder', $order, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
    }

    public function test_pedido_em_revisao_nao_tem_botao_de_cancelar_e_chamada_forcada_nao_muda_nada(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $this->addPaymentReviewLog($order);

        $component = Livewire::test(ListOrders::class)->assertTableActionHidden('cancelOrder', $order);
        // Requisição forjada: chama os métodos Livewire direto, ignorando o botão oculto
        $component
            ->call('mountTableAction', 'cancelOrder', $order->getKey())
            ->set('mountedTableActionsData.0.reason', 'forjado')
            ->call('callMountedTableAction');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(1, $order->productionLogs()->count(), 'Só o registro de revisão pré-existente.');
    }

    public function test_chamada_forcada_de_cancelar_pedido_pago_nao_muda_nada(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();

        Livewire::test(ListOrders::class)
            ->call('mountTableAction', 'cancelOrder', $order->getKey())
            ->set('mountedTableActionsData.0.reason', 'forjado')
            ->call('callMountedTableAction');

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
    }

    public function test_pagamento_aprovado_antes_do_clique_nao_e_cancelado(): void
    {
        // Modal aberto com o pedido pendente; o webhook aprova o pagamento logo depois que o Filament lê o registro.
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $component = Livewire::test(ListOrders::class)
            ->mountTableAction('cancelOrder', $order)
            ->setTableActionData(['reason' => 'Sem pagamento']);

        $this->afterNextRead($order, fn () => DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::IN_QUEUE->value]));
        $component->callMountedTableAction();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertSame(0, $order->productionLogs()->count());
        $component->assertNotified(self::STATUS_CHANGED_MESSAGE);
    }

    public function test_pedido_que_entra_em_revisao_antes_do_clique_nao_e_cancelado(): void
    {
        // O webhook registra um pagamento divergente (revisão) logo depois que o Filament lê o registro:
        // o botão ainda estava visível, mas o UPDATE condicional recusa.
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create();
        $component = Livewire::test(ListOrders::class)
            ->mountTableAction('cancelOrder', $order)
            ->setTableActionData(['reason' => 'Sem pagamento']);

        $this->afterNextRead($order, fn () => $this->addPaymentReviewLog($order));
        $component->callMountedTableAction();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(1, $order->productionLogs()->count(), 'Só o registro de revisão.');
        $component->assertNotified(self::STATUS_CHANGED_MESSAGE);
    }

    public function test_pagamento_aprovado_depois_do_cancelamento_pelo_painel_vai_para_revisao_sem_reabrir(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING_PAYMENT)->create(['total_cents' => 10000]);
        Livewire::test(ListOrders::class)
            ->callTableAction('cancelOrder', $order, data: ['reason' => 'PIX não pago'])
            ->assertHasNoTableActionErrors();

        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(123, 'approved', $order->order_number, 100.0), 200);
        $this->postSignedWebhook('123')->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $fresh->status);
        $this->assertTrue($fresh->isInPaymentReview());
        $this->assertSame(1, $order->productionLogs()->where('code', ProductionLog::CODE_PAYMENT_AFTER_CANCELLATION)->count());
    }

    public function test_marcar_como_entregue_grava_status_e_log_sem_email(): void
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create(['customer_email' => self::CUSTOMER_EMAIL]);

        Livewire::test(ListOrders::class)
            ->callTableAction('markAsDelivered', $order)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Encomenda Entregue!');

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $log = $order->productionLogs()->sole();
        $this->assertSame(
            ['shipped', 'delivered', 'Encomenda entregue ao cliente.', ProductionLog::CODE_STATUS_CHANGE, false],
            [$log->previous_status, $log->new_status, $log->notes, $log->code, $log->is_internal]
        );
        Mail::assertNothingOutgoing();
    }

    public function test_iniciar_impressao_pelo_painel_manda_um_email_ao_cliente(): void
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create(['customer_email' => self::CUSTOMER_EMAIL]);

        Livewire::test(ListOrders::class)->callTableAction('startPrinting', $order);

        Mail::assertSent(OrderPrintingStartedMail::class, 1);
        Mail::assertSent(OrderPrintingStartedMail::class, fn (OrderPrintingStartedMail $m): bool => $m->hasTo(self::CUSTOMER_EMAIL));
        Mail::assertNotSent(OrderShippedMail::class);
    }

    public function test_expedir_pelo_painel_manda_um_email_com_o_codigo_de_rastreio(): void
    {
        $order = Order::factory()->status(OrderStatus::FINISHING_AND_PACKAGING)->create(['customer_email' => self::CUSTOMER_EMAIL]);

        Livewire::test(ListOrders::class)
            ->callTableAction('markAsShipped', $order, data: ['carrier_tracking_code' => 'BR123456789BR'])
            ->assertHasNoTableActionErrors();

        Mail::assertSent(OrderShippedMail::class, 1);
        Mail::assertSent(
            OrderShippedMail::class,
            fn (OrderShippedMail $m): bool => $m->hasTo(self::CUSTOMER_EMAIL) && $m->order->carrier_tracking_code === 'BR123456789BR'
        );
        Mail::assertNotSent(OrderPrintingStartedMail::class);
    }

    public function test_acabamento_pelo_painel_nao_manda_email(): void
    {
        $order = Order::factory()->status(OrderStatus::PRINTING)->create();

        Livewire::test(ListOrders::class)->callTableAction('startPackaging', $order);

        $this->assertSame(OrderStatus::FINISHING_AND_PACKAGING, $order->fresh()->status);
        Mail::assertNothingOutgoing();
    }

    private function addPaymentReviewLog(Order $order): void
    {
        ProductionLog::factory()->create([
            'order_id' => $order->id,
            'previous_status' => 'pending_payment',
            'new_status' => 'pending_payment',
            'notes' => '[Sistema] revisão',
            'is_internal' => true,
            'code' => ProductionLog::CODE_PAYMENT_REVIEW,
            'reference' => '123',
        ]);
    }

    /**
     * Simula outro processo (webhook): roda $change logo após a próxima leitura do pedido pelo Filament.
     */
    private function afterNextRead(Order $order, callable $change): void
    {
        $armed = true;
        Order::retrieved(function (Order $retrieved) use (&$armed, $order, $change): void {
            if (! $armed || ! $retrieved->is($order)) {
                return;
            }
            $armed = false;
            $change();
        });
    }
}
