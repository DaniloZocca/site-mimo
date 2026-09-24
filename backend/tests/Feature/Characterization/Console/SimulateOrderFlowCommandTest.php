<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Console;

use App\Enums\OrderStatus;
use App\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * mimoo:simulate-order-flow — simulação local de ponta a ponta (catálogo → checkout → webhook).
 * O pagamento aprovado vem de um cliente HTTP falso registrado pelo próprio comando.
 */
#[Group('characterization')]
class SimulateOrderFlowCommandTest extends CharacterizationTestCase
{
    public function test_simulacao_leva_o_pedido_ate_a_fila_pelo_webhook_real(): void
    {
        $this->artisan('mimoo:simulate-order-flow', ['--method' => 'pix'])->assertExitCode(0);

        $order = Order::sole();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->productionLogs()->where('notes', 'like', 'Pagamento aprovado via Webhook Mercado Pago (Payment ID: %')->exists());
        $this->assertCount(0, $this->mpHttp->requests, 'O cliente falso do teste é restaurado e não é usado pelo comando.');
    }

    public function test_recusa_rodar_em_producao(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('mimoo:simulate-order-flow')->assertExitCode(1);

        $this->assertSame(0, Order::count());
    }

    public function test_recusa_rodar_sem_segredo_do_webhook(): void
    {
        $this->setWebhookSecret('');

        $this->artisan('mimoo:simulate-order-flow')->assertExitCode(1);

        $this->assertSame(0, Order::count());
    }
}
