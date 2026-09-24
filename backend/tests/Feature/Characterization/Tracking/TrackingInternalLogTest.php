<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Tracking;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductionLog;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Registros internos (is_internal) nunca aparecem na timeline pública do rastreio.
 */
#[Group('characterization')]
class TrackingInternalLogTest extends CharacterizationTestCase
{
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = Order::factory()->create(['order_number' => 'MIMOO-INT00001']);
        ProductionLog::factory()->create([
            'order_id' => $this->order->id,
            'previous_status' => 'checkout',
            'new_status' => OrderStatus::PENDING_PAYMENT->value,
            'notes' => 'Pedido criado.',
        ]);
        ProductionLog::factory()->create([
            'order_id' => $this->order->id,
            'previous_status' => OrderStatus::PENDING_PAYMENT->value,
            'new_status' => OrderStatus::PENDING_PAYMENT->value,
            'notes' => '[Sistema] Pagamento 123 aprovado com divergência (amount_mismatch) — revisão manual; produção não liberada.',
            'is_internal' => true,
            'code' => ProductionLog::CODE_PAYMENT_REVIEW,
            'reference' => '123',
        ]);
    }

    public function test_post_por_token_nao_expoe_log_interno(): void
    {
        $response = $this->postJson('/api/orders/track', ['tracking_token' => $this->order->tracking_token])->assertOk();

        $this->assertCount(1, $response->json('timeline'));
        $this->assertStringNotContainsString('divergência', $response->getContent());
        $this->assertStringNotContainsString('amount_mismatch', $response->getContent());
    }

    public function test_get_por_token_nao_expoe_log_interno(): void
    {
        $response = $this->getJson('/api/orders/track?tracking_token='.$this->order->tracking_token)->assertOk();

        $this->assertCount(1, $response->json('timeline'));
        $this->assertStringNotContainsString('divergência', $response->getContent());
    }

    public function test_post_por_numero_e_cpf_nao_expoe_log_interno(): void
    {
        $cpfPrefix = substr(preg_replace('/\D/', '', (string) $this->order->customer_cpf), 0, 5);

        $response = $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-INT00001', 'cpf_prefix' => $cpfPrefix])->assertOk();

        $this->assertCount(1, $response->json('timeline'));
        $this->assertStringNotContainsString('divergência', $response->getContent());
    }

    public function test_log_interno_continua_na_relacao_usada_pelo_painel(): void
    {
        $this->assertSame(2, $this->order->productionLogs()->count());
        $this->assertSame(1, $this->order->productionLogs()->public()->count());
        $this->assertSame(1, $this->order->productionLogs()->paymentReview()->count());
    }
}
