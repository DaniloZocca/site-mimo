<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Order;
use App\Services\ShippingCalculationService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Campos monetários vindos do cliente: quais são ignorados e quais entram no total.
 */
#[Group('characterization')]
class CheckoutZeroTrustTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    public function test_campos_extras_de_preco_no_payload_sao_ignorados(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $item = $this->itemPayload($product, $color, null, 1, 'pla', [
            'unit_price_cents' => 1,
            'price' => 0.01,
            'base_price_cents' => 1,
            'total_cents' => 1,
        ]);

        $this->postJson('/api/checkout', $this->checkoutPayload([$item], 'credit_card', self::SERVER_PAC_CENTS, [
            'total_cents' => 1,
            'subtotal_cents' => 1,
            'discount_cents' => 999999,
            'total_formatted' => 'R$ 0,01',
        ]))->assertStatus(201)->assertJsonPath('total_cents', 5000 + self::SERVER_PAC_CENTS);

        $this->assertSame(5000, Order::sole()->items()->sole()->unit_price_cents);
    }

    /**
     * Bug #4 corrigido (era gêmeo em KnownBugs): o frete é recalculado no servidor; valor divergente → 409.
     */
    public function test_frete_e_recalculado_no_servidor_e_ignora_o_valor_do_cliente(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));
        $items = [$this->itemPayload($product, $color)];

        // Valor divergente do calculado no servidor: 409 e nenhum pedido (não cobra valor diferente do exibido).
        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', 0))
            ->assertStatus(409)->assertJsonPath('code', 'shipping_price_changed');
        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', 99999999))
            ->assertStatus(409)->assertJsonPath('code', 'shipping_price_changed');
        $this->assertSame(0, Order::count());

        // Sem valor do cliente: o servidor cobra o frete que ele mesmo calculou.
        $serverShipping = null;
        foreach (app(ShippingCalculationService::class)->calculate('01001000', [['product_id' => $product->id, 'size_id' => null, 'quantity' => 1]]) as $option) {
            if ($option->serviceCode === 'pac') {
                $serverShipping = $option->priceInCents;
            }
        }

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', null))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 5000 + $serverShipping);
        $this->assertNotSame(0, Order::sole()->shipping_cost_cents, 'Frete 0 do cliente não deveria ser aceito.');
        $this->assertSame($serverShipping, Order::sole()->shipping_cost_cents);
    }

    public function test_frete_so_no_no_shipping_e_ignorado(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card', null);
        $payload['shipping']['shipping_cost_cents'] = 777;

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('total_cents', 5000 + self::SERVER_PAC_CENTS);
    }

    public function test_no_shipping_e_ignorado_mesmo_com_a_raiz_presente(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card');
        $payload['shipping']['shipping_cost_cents'] = 1;

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('total_cents', 5000 + self::SERVER_PAC_CENTS);
    }

    public function test_cost_in_cents_sem_a_raiz_e_ignorado_e_o_servidor_cobra_o_frete(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card', null);
        $payload['shipping']['cost_in_cents'] = 1500;

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('total_cents', 5000 + self::SERVER_PAC_CENTS);
    }

    public function test_frete_negativo_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'pix', -1))
            ->assertStatus(422)
            ->assertJsonPath('errors.shipping_cost_cents.0', 'O frete não pode ser negativo.');

        $this->assertSame(0, Order::count());
    }
}
