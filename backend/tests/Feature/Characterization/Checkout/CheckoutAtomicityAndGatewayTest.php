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

    /**
     * Ex-gêmeo de KnownBugs (bug #8, corrigido no M-08).
     */
    public function test_falha_do_gateway_mantem_pedido_pendente_e_responde_502_generico(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $response = $this->postJson('/api/checkout', $payload);

        $response->assertStatus(502);
        $this->assertStringNotContainsString('cURL', (string) $response->json('message'));
        $this->assertStringNotContainsString('Falha na autorização', (string) $response->json('message'));
        $this->assertSame(OrderStatus::PENDING_PAYMENT, Order::sole()->status);
    }

    public function test_timeout_do_gateway_no_cartao_responde_502_com_mensagem_amigavel_e_sem_log_de_status(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $response = $this->postJson('/api/checkout', $payload);

        $order = Order::sole();
        $response->assertStatus(502)->assertExactJson([
            'code' => 'payment_gateway_error',
            'message' => "Não conseguimos confirmar seu pagamento agora 😕 Seu pedido #{$order->order_number} ficou guardado. "
                .'Se a cobrança aparecer no seu cartão, ele é confirmado sozinho e você recebe um e-mail. '
                .'Para não pagar duas vezes, confira em "Rastrear pedido" antes de tentar de novo.',
            'order_number' => $order->order_number,
        ]);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $pairs = $order->productionLogs()->get()->map(fn ($l): string => "{$l->previous_status}->{$l->new_status}")->all();
        $this->assertSame(['checkout->pending_payment'], $pairs);

        $payment = $order->payments()->sole();
        $this->assertSame('error', $payment->status);
        $this->assertSame(502, $payment->http_status);
        $this->assertSame('pending_payment', $payment->order_status);
    }

    public function test_erro_500_da_api_no_pix_responde_502_e_pedido_fica_pendente(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->mpHttp->pushApiError(500);

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(502)
            ->assertJsonPath('code', 'payment_gateway_error')
            ->assertJsonPath('message', 'Não conseguimos gerar o seu PIX agora 😕 Nenhuma cobrança foi feita. Tente de novo em alguns instantes.');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, Order::sole()->status);
    }

    public function test_pedido_com_falha_do_gateway_continua_rastreavel(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('timeout'));

        $orderNumber = $this->postJson('/api/checkout', $payload)->json('order_number');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, Order::where('order_number', $orderNumber)->sole()->status);
    }
}
