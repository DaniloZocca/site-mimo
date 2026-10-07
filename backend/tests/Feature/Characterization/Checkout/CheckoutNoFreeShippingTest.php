<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Mail\OrderConfirmedMail;
use App\Models\Order;
use App\Services\ShippingCalculationService;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Decisão de negócio: não existe frete grátis (nem acima de R$ 199). O frete cobrado é sempre o
 * recalculado no servidor, qualquer que seja o subtotal das peças. O front (src/utils/pricing.js)
 * espelha a mesma regra; se um dia houver frete grátis, a regra nasce aqui no backend.
 */
#[Group('characterization')]
class CheckoutNoFreeShippingTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const CEP = '01001000';

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function serverPacCents(array $items): int
    {
        $quoteItems = array_map(fn (array $i): array => [
            'product_id' => $i['product_id'],
            'size_id' => $i['size_id'] ?? null,
            'quantity' => $i['quantity'],
        ], $items);

        foreach (app(ShippingCalculationService::class)->calculate(self::CEP, $quoteItems) as $option) {
            if ($option->serviceCode === 'pac') {
                return $option->priceInCents;
            }
        }

        $this->fail('Serviço pac não cotado.');
    }

    public function test_pix_acima_de_199_cobra_o_frete_do_servidor(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 25000]);
        $items = [$this->itemPayload($product, $color)];
        $shipping = $this->serverPacCents($items);
        $this->assertGreaterThan(0, $shipping);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'pix', $shipping))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 25000 - 2500 + $shipping);

        $this->assertSame($shipping, Order::sole()->shipping_cost_cents);
    }

    public function test_cartao_acima_de_199_cobra_o_frete_do_servidor(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 25000]);
        $items = [$this->itemPayload($product, $color)];
        $shipping = $this->serverPacCents($items);
        $this->assertGreaterThan(0, $shipping);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'credit_card', $shipping))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 25000 + $shipping);

        $this->assertSame($shipping, Order::sole()->shipping_cost_cents);
    }

    public function test_pix_com_subtotal_exatamente_199_cobra_o_frete_do_servidor(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem(['base_price_cents' => 19900]);
        $items = [$this->itemPayload($product, $color)];
        $shipping = $this->serverPacCents($items);
        $this->assertGreaterThan(0, $shipping);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));

        $this->postJson('/api/checkout', $this->checkoutPayload($items, 'pix', $shipping))
            ->assertStatus(201)
            ->assertJsonPath('total_cents', 19900 - 1990 + $shipping);

        $this->assertSame($shipping, Order::sole()->shipping_cost_cents);
    }

    public function test_email_de_confirmacao_com_frete_zero_nao_mostra_gratis(): void
    {
        $order = Order::factory()->create(['shipping_cost_cents' => 0]);

        // O construtor carrega relações inexistentes em Order (bug #13); aqui só importa o texto do frete.
        $mail = (new ReflectionClass(OrderConfirmedMail::class))->newInstanceWithoutConstructor();
        $mail->order = $order;

        $this->assertSame('R$ 0,00', $mail->content()->with['shippingFormatted']);
    }
}
