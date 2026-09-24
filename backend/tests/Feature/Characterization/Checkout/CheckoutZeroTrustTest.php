<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Order;
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

        $this->postJson('/api/checkout', $this->checkoutPayload([$item], 'credit_card', 1000, [
            'total_cents' => 1,
            'subtotal_cents' => 1,
            'discount_cents' => 999999,
            'total_formatted' => 'R$ 0,01',
        ]))->assertStatus(201)->assertJsonPath('total_cents', 6000);

        $this->assertSame(5000, Order::sole()->items()->sole()->unit_price_cents);
    }

    public function test_frete_zero_enviado_pelo_cliente_vai_para_o_total(): void
    {
        // BUG CONHECIDO: shipping_cost_cents do cliente entra no total sem recálculo — ver teste gêmeo em KnownBugs
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card', 0))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 5000);

        $this->assertSame(0, Order::sole()->shipping_cost_cents);
    }

    public function test_frete_absurdo_enviado_pelo_cliente_vai_para_o_total_e_para_o_gateway(): void
    {
        // BUG CONHECIDO: shipping_cost_cents do cliente entra no total sem recálculo — ver teste gêmeo em KnownBugs
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card', 99999999))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 100004999)
            ->assertJsonPath('total_formatted', 'R$ 1.000.049,99');

        $this->assertEqualsWithDelta(1000049.99, $this->mpHttp->lastPayload()['transaction_amount'], 0.001);
    }

    public function test_frete_so_no_no_shipping_e_copiado_para_a_raiz(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card');
        unset($payload['shipping_cost_cents']);
        $payload['shipping']['shipping_cost_cents'] = 777;

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('total_cents', 5777);
    }

    public function test_no_shipping_tem_prioridade_sobre_a_raiz_quando_os_dois_existem(): void
    {
        // CheckoutOrderDTO: shipping.shipping_cost_cents ?? raiz ?? shipping.cost_in_cents
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'approved'));

        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card', 1000);
        $payload['shipping']['shipping_cost_cents'] = 1;

        $this->postJson('/api/checkout', $payload)->assertStatus(201)->assertJsonPath('total_cents', 5001);
    }

    public function test_so_cost_in_cents_sem_a_raiz_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card');
        unset($payload['shipping_cost_cents']);
        $payload['shipping']['cost_in_cents'] = 1500;

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.shipping_cost_cents.0', 'O valor do frete em centavos é obrigatório.');
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
