<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-06 (S5 da auditoria de 2026-11-27): no máximo 50 linhas na sacola, no checkout e na cotação de frete.
 * Antes, cada linha passava por até 3 consultas `exists` sem limite de linhas: 10.000 linhas prendiam o
 * servidor por ~17 s. Acima do limite, as regras de cada item nem são montadas: 422 sem tocar no banco.
 */
class CheckoutItemLinesLimitTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const LIMIT_MESSAGE = 'A sacola aceita até 50 itens diferentes por pedido. Para uma encomenda maior, divida em mais de um pedido ou fale com a gente.';

    private Product $product;

    private Color $color;

    protected function setUp(): void
    {
        parent::setUp();

        ['product' => $this->product, 'color' => $this->color] = $this->catalogItem();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(int $count): array
    {
        return array_fill(0, $count, $this->itemPayload($this->product, $this->color));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shippingLines(int $count): array
    {
        return array_fill(0, $count, ['product_id' => $this->product->id, 'size_id' => null, 'quantity' => 1]);
    }

    /**
     * @param  callable(): TestResponse  $request
     * @return array{response: TestResponse, queries: int}
     */
    private function countingQueries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $request();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['response' => $response, 'queries' => $queries];
    }

    public function test_checkout_com_51_linhas_responde_422_sem_pedido_e_sem_chamar_o_mercado_pago(): void
    {
        $response = $this->postJson('/api/checkout', $this->checkoutPayload($this->lines(51), 'pix', null));

        $response->assertStatus(422)->assertJsonPath('errors.items', [self::LIMIT_MESSAGE]);
        $this->assertSame(self::LIMIT_MESSAGE, $response->json('message'));
        $this->assertSame(0, Order::count());
        $this->assertSame([], $this->mpHttp->requests);
    }

    public function test_checkout_com_10000_linhas_responde_422_sem_nenhuma_consulta_ao_banco(): void
    {
        $payload = $this->checkoutPayload($this->lines(10000), 'pix', null);

        ['response' => $response, 'queries' => $queries] = $this->countingQueries(
            fn (): TestResponse => $this->postJson('/api/checkout', $payload)
        );

        $response->assertStatus(422)->assertJsonPath('errors.items', [self::LIMIT_MESSAGE]);
        $this->assertSame(0, $queries);
    }

    public function test_checkout_com_mais_de_50_linhas_continua_mostrando_os_outros_erros(): void
    {
        $payload = $this->checkoutPayload($this->lines(51), 'pix', null);
        $payload['customer']['name'] = '';

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items', 'customer.name']);
    }

    public function test_checkout_com_exatamente_50_linhas_cria_o_pedido_com_os_50_itens(): void
    {
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9050, 'pending', statusDetail: 'pending_waiting_transfer'));

        $this->postJson('/api/checkout', $this->checkoutPayload($this->lines(50), 'pix', null))->assertStatus(201);

        $order = Order::sole();
        $this->assertSame(50, $order->items()->count());
        // 50 × 5000 = 250000 nas peças; PIX: 250000 − round(250000 × 0,10) = 225000; frete à parte, sem desconto.
        $subtotal = 50 * $this->product->base_price_cents;
        $this->assertSame($subtotal - intdiv($subtotal * 10 + 50, 100) + $order->shipping_cost_cents, $order->total_cents);
    }

    public function test_frete_com_51_linhas_responde_422_com_a_mensagem_propria(): void
    {
        $this->postJson('/api/shipping/calculate', ['destination_cep' => '01001-000', 'items' => $this->shippingLines(51)])
            ->assertStatus(422)
            ->assertJsonPath('errors.items', [self::LIMIT_MESSAGE]);
    }

    public function test_frete_com_10000_linhas_responde_422_sem_nenhuma_consulta_ao_banco(): void
    {
        $payload = ['destination_cep' => '01001-000', 'items' => $this->shippingLines(10000)];

        ['response' => $response, 'queries' => $queries] = $this->countingQueries(
            fn (): TestResponse => $this->postJson('/api/shipping/calculate', $payload)
        );

        $response->assertStatus(422)->assertJsonPath('errors.items', [self::LIMIT_MESSAGE]);
        $this->assertSame(0, $queries);
    }

    public function test_frete_com_exatamente_50_linhas_devolve_as_opcoes(): void
    {
        $this->postJson('/api/shipping/calculate', ['destination_cep' => '01001-000', 'items' => $this->shippingLines(50)])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('options.0.service_code', fn (mixed $code): bool => is_string($code) && $code !== '');
    }
}
