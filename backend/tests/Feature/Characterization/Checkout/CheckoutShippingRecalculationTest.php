<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Models\Order;
use App\Services\ShippingCalculationService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Bug #4: o frete é recalculado no servidor (ShippingCalculationService) a partir do serviço escolhido.
 * shipping_cost_cents do cliente serve só para detectar divergência (409).
 */
#[Group('characterization')]
class CheckoutShippingRecalculationTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const CEP = '01001000';

    /**
     * Opção que o servidor calcula para o carrinho (mesmo caminho da cotação pública).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function serverOption(array $items, string $serviceCode = 'pac'): array
    {
        $quoteItems = array_map(fn (array $i): array => [
            'product_id' => $i['product_id'],
            'size_id' => $i['size_id'] ?? null,
            'quantity' => $i['quantity'],
        ], $items);

        foreach (app(ShippingCalculationService::class)->calculate(self::CEP, $quoteItems) as $option) {
            if ($option->serviceCode === $serviceCode) {
                return $option->toArray();
            }
        }

        $this->fail("Serviço {$serviceCode} não cotado.");
    }

    public function test_frete_zero_no_payload_e_recusado_com_409_sem_criar_pedido(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $items = [$this->itemPayload($product, $color)];
        $expected = $this->serverOption($items);

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'pix', 0))
            ->assertStatus(409)
            ->assertJsonPath('code', 'shipping_price_changed')
            ->assertJsonPath('shipping_service', 'pac')
            ->assertJsonPath('shipping_cost_cents', $expected['price_in_cents'])
            ->assertJsonPath('shipping_options.0.service_code', 'pac')
            ->assertJsonPath('shipping_options.1.service_code', 'sedex');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, count($this->mpHttp->requests));
    }

    public function test_frete_absurdo_no_payload_e_recusado_com_409_e_nao_chega_ao_gateway(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card', 99999999))
            ->assertStatus(409)
            ->assertJsonPath('code', 'shipping_price_changed');

        $this->assertStringContainsString('frete', mb_strtolower((string) $response->json('message')));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, count($this->mpHttp->requests));
    }

    public function test_sem_shipping_cost_cents_o_servidor_cobra_o_frete_calculado(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $items = [$this->itemPayload($product, $color)];
        $expected = $this->serverOption($items, 'sedex');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', null, ['shipping_service' => 'sedex']))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 5000 + $expected['price_in_cents']);

        $this->assertSame($expected['price_in_cents'], Order::sole()->shipping_cost_cents);
    }

    public function test_valor_exibido_igual_ao_do_servidor_cria_o_pedido(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $items = [$this->itemPayload($product, $color)];
        $expected = $this->serverOption($items);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', $expected['price_in_cents']))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 5000 + $expected['price_in_cents']);
    }

    public function test_servico_inexistente_retorna_422_sem_criar_pedido(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();

        $this->postJson('/api/checkout', $this->checkoutPayload([$this->itemPayload($product, $color)], 'pix', null, ['shipping_service' => 'teletransporte']))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['shipping_service']]);

        $this->assertSame(0, Order::count());
    }

    public function test_sem_shipping_service_retorna_422(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)]);
        unset($payload['shipping_service']);

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['shipping_service']]);

        $this->assertSame(0, Order::count());
    }

    public function test_snapshot_completo_do_frete_vindo_do_melhor_envio(): void
    {
        config(['services.shipping.melhor_envio.token' => 'token-melhor-envio-teste']);
        Http::fake(['*/api/v2/me/shipment/calculate' => Http::response([
            ['id' => 1, 'name' => 'PAC', 'price' => '18.37', 'delivery_time' => 4, 'company' => ['name' => 'Correios', 'picture' => null]],
            ['id' => 2, 'name' => 'SEDEX', 'price' => '31.02', 'delivery_time' => 2, 'company' => ['name' => 'Correios', 'picture' => null]],
        ], 200)]);

        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $items = [$this->itemPayload($product, $color)];
        $expected = $this->serverOption($items, 'sedex');
        $this->assertSame(3102, $expected['price_in_cents']);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', 3102, ['shipping_service' => 'sedex']))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 8102);

        $order = Order::sole();
        $this->assertSame(3102, $order->shipping_cost_cents);
        $this->assertSame('sedex', $order->shipping_service_code);
        $this->assertSame('SEDEX', $order->shipping_service_name);
        $this->assertSame('Correios', $order->shipping_carrier_name);
        $this->assertSame($expected['total_estimated_days'], $order->shipping_delivery_days);
        $this->assertSame('melhor_envio', $order->shipping_price_source);
    }

    public function test_melhor_envio_fora_do_ar_usa_fallback_regional_e_grava_a_origem(): void
    {
        config(['services.shipping.melhor_envio.token' => 'token-melhor-envio-teste']);
        Http::fake(['*/api/v2/me/shipment/calculate' => Http::response(['message' => 'Server Error'], 500)]);

        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 5000]);
        $items = [$this->itemPayload($product, $color)];
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', null))
            ->assertStatus(201);

        $order = Order::sole();
        $expected = $this->serverOption($items); // cotação em cache (a do checkout)
        $this->assertTrue($expected['metadata']['fallback']);
        $this->assertSame($expected['price_in_cents'], $order->shipping_cost_cents);
        $this->assertSame('fallback', $order->shipping_price_source);
        $this->assertSame('pac', $order->shipping_service_code);
        $this->assertSame(5000 + $expected['price_in_cents'], $order->total_cents);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/me/shipment/calculate'));
    }

    public function test_desconto_pix_incide_so_nas_pecas_e_nao_no_frete_do_servidor(): void
    {
        // 3333 × 3 = 9999 → desconto round(999,9) = 1000 → peças 8999; frete do servidor sem desconto.
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 3333]);
        $items = [$this->itemPayload($product, $color, null, 3)];
        $shipping = $this->serverOption($items)['price_in_cents'];
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'pix', $shipping))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 8999 + $shipping);

        $this->assertSame($shipping, Order::sole()->shipping_cost_cents);
    }

    public function test_total_e_pecas_menos_desconto_pix_mais_frete_do_servidor_com_tamanho(): void
    {
        ['product' => $product, 'color' => $color, 'size' => $size] = $this->catalogItem(['base_price_cents' => 4990], 1500);
        $items = [$this->itemPayload($product, $color, $size, 2)];
        $shipping = $this->serverOption($items)['price_in_cents'];
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $pieces = (4990 + 1500) * 2;              // 12980
        $discount = intdiv($pieces * 10 + 50, 100); // round(1298,0) = 1298

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'pix', null))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', $pieces - $discount + $shipping);
    }
}
