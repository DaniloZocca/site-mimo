<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionLog;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Atomicidade da criação do pedido e falha do gateway depois do commit.
 */
#[Group('characterization')]
class CheckoutAtomicityAndGatewayTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    public function test_um_item_valido_e_um_inativo_nao_persiste_nada(): void
    {
        ['product' => $ok, 'color' => $color] = $this->catalogItem();
        $inactive = Product::factory()->inactive()->create();
        $inactive->colors()->attach($color->id);

        $this->postJson('/api/checkout', $this->checkoutPayload([
            $this->itemPayload($ok, $color),
            $this->itemPayload($inactive, $color),
        ]))->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, ProductionLog::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_falha_ao_gravar_o_segundo_item_desfaz_o_pedido_inteiro(): void
    {
        ['product' => $a, 'color' => $color] = $this->catalogItem();
        ['product' => $b] = $this->catalogItem();
        $b->colors()->attach($color->id);

        // Falha simulada do banco no 2º insert de order_items (gancho só no teste).
        $inserts = 0;
        OrderItem::creating(function () use (&$inserts): void {
            if (++$inserts === 2) {
                throw new RuntimeException('falha simulada no insert');
            }
        });

        $this->postJson('/api/checkout', $this->checkoutPayload([
            $this->itemPayload($a, $color),
            $this->itemPayload($b, $color),
        ]))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'falha simulada no insert']);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, ProductionLog::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_timeout_do_gateway_no_cartao_cancela_o_pedido_e_repete_a_mensagem(): void
    {
        // BUG CONHECIDO: falha do gateway após o commit cancela o pedido e vaza a mensagem — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $response = $this->postJson('/api/checkout', $payload);

        $order = Order::sole();
        $response->assertStatus(422)->assertExactJson([
            'message' => 'Não foi possível processar a cobrança. Falha na autorização do cartão de crédito: cURL error 28: Operation timed out after 30001 milliseconds',
            'order_number' => $order->order_number,
        ]);

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $pairs = $order->productionLogs()->get()->map(fn ($l): string => "{$l->previous_status}->{$l->new_status}")->sort()->values()->all();
        $this->assertSame(['checkout->pending_payment', 'pending_payment->cancelled'], $pairs);
        $this->assertTrue($order->productionLogs()->where('notes', 'like', 'Cobrança não concluída no gateway: Falha na autorização do cartão de crédito: cURL error 28%')->exists());
    }

    public function test_erro_500_da_api_no_pix_cancela_o_pedido(): void
    {
        // BUG CONHECIDO: falha do gateway após o commit cancela o pedido e vaza a mensagem — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->mpHttp->pushApiError(500);

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não foi possível processar a cobrança. Falha ao comunicar com o gateway PIX: Api error. Check response for details');

        $this->assertSame(OrderStatus::CANCELLED, Order::sole()->status);
    }

    public function test_pedido_cancelado_por_falha_do_gateway_continua_rastreavel(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('timeout'));

        $orderNumber = $this->postJson('/api/checkout', $payload)->json('order_number');

        $this->assertSame(OrderStatus::CANCELLED, Order::where('order_number', $orderNumber)->sole()->status);
    }
}
