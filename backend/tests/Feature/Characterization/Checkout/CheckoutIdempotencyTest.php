<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\DTOs\CheckoutItemDTO;
use App\DTOs\CheckoutOrderDTO;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\CheckoutIdempotencyService;
use App\Services\MercadoPagoService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Bug #7: POST /api/checkout idempotente pelo header Idempotency-Key (UUID).
 */
#[Group('characterization')]
class CheckoutIdempotencyTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkout(array $payload, string $key): TestResponse
    {
        return $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key]);
    }

    /**
     * @return array<int, string>
     */
    private function sentIdempotencyHeaders(): array
    {
        $keys = [];
        foreach ($this->mpHttp->requests as $request) {
            foreach ($request->getHeaders() as $header) {
                if (str_starts_with(strtolower($header), 'x-idempotency-key:')) {
                    $keys[] = trim(substr($header, strlen('x-idempotency-key:')));
                }
            }
        }

        return $keys;
    }

    public function test_envio_duplicado_de_pix_cria_um_pedido_e_devolve_a_resposta_identica(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending', statusDetail: 'pending_waiting_transfer'));
        $key = (string) Str::uuid();

        $first = $this->checkout($payload, $key)->assertStatus(201);
        $second = $this->checkout($payload, $key)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame('00020126PIXCOPIAECOLA', $second->json('payment.qr_code'));
        $this->assertSame(1, Order::count());
        $this->assertCount(1, $this->mpHttp->requests, 'O Mercado Pago deve ser chamado uma única vez.');
    }

    public function test_cartao_replay_com_novo_card_token_nao_cobra_de_novo(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));
        $key = (string) Str::uuid();

        $first = $this->checkout($payload, $key)->assertStatus(201)->assertJsonPath('status', 'in_queue');

        // Nova tokenização do Brick gera outro card_token: não muda o fingerprint.
        $payload['payment']['card_token'] = 'card-token-nova-tokenizacao';
        $second = $this->checkout($payload, $key)->assertStatus(201);

        $this->assertSame($first->json(), $second->json());
        $this->assertCount(1, $this->mpHttp->requests, 'O replay não pode criar outra cobrança.');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderPayment::count());
    }

    public function test_replay_de_falha_do_gateway_devolve_a_mesma_resposta_sem_chamar_o_gateway(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out'));
        $key = (string) Str::uuid();

        $first = $this->checkout($payload, $key);
        $second = $this->checkout($payload, $key);

        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->json(), $second->json());
        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame(1, Order::count());
    }

    public function test_mesma_chave_com_payload_diferente_retorna_422(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(1, 'pending'));
        $key = (string) Str::uuid();

        $this->checkout($payload, $key)->assertStatus(201);

        $payload['items'][0]['quantity'] = 2;
        $this->checkout($payload, $key)
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_key_reused');

        $this->assertSame(1, Order::count());
        $this->assertCount(1, $this->mpHttp->requests);
    }

    public function test_sem_header_retorna_400_sem_criar_pedido(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');

        $this->checkout($payload, '')
            ->assertStatus(400)
            ->assertJsonPath('code', 'idempotency_key_required');

        $this->assertSame(0, Order::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_header_que_nao_e_uuid_retorna_400(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');

        $this->checkout($payload, 'mesma-chave-do-front')
            ->assertStatus(400)
            ->assertJsonPath('code', 'idempotency_key_required');

        $this->assertSame(0, Order::count());
    }

    public function test_pedido_com_a_mesma_chave_ainda_sem_resposta_retorna_409_request_in_progress(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $key = (string) Str::uuid();
        $existing = Order::factory()->create();
        $existing->idempotency_key = $key;
        $existing->request_fingerprint = CheckoutIdempotencyService::fingerprint($payload);
        $existing->save();

        $this->checkout($payload, $key)
            ->assertStatus(409)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('code', 'request_in_progress');

        $this->assertSame(1, Order::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_violacao_do_unique_na_corrida_nao_cria_duplicata_e_devolve_o_pedido_existente(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $key = (string) Str::uuid();

        // Pedido "vencedor" da corrida, já com resposta pronta.
        $winner = Order::factory()->create();
        $winner->idempotency_key = $key;
        $winner->request_fingerprint = CheckoutIdempotencyService::fingerprint($payload);
        $winner->payment_idempotency_key = (string) Str::uuid();
        $winner->save();
        OrderPayment::create([
            'order_id' => $winner->id,
            'provider' => 'mercadopago',
            'method' => 'pix',
            'external_id' => '555',
            'status' => 'pending',
            'status_detail' => 'pending_waiting_transfer',
            'qr_code' => 'QR-DO-VENCEDOR',
            'qr_code_base64' => 'b64',
            'ticket_url' => 'https://example.com/ticket',
            'http_status' => 201,
            'order_status' => OrderStatus::PENDING_PAYMENT->value,
        ]);

        // Janela da corrida: a busca antecipada ainda não enxerga o vencedor; o INSERT bate no UNIQUE.
        $real = app(CheckoutIdempotencyService::class);
        $calls = 0;
        $this->partialMock(CheckoutIdempotencyService::class, function ($mock) use ($real, &$calls): void {
            $mock->shouldReceive('findByKey')->andReturnUsing(function (string $k) use ($real, &$calls) {
                return ++$calls === 1 ? null : $real->findByKey($k);
            });
        });

        $this->checkout($payload, $key)
            ->assertStatus(201)
            ->assertJsonPath('order_number', $winner->order_number)
            ->assertJsonPath('payment.qr_code', 'QR-DO-VENCEDOR');

        $this->assertSame(2, $calls, 'A violação do UNIQUE deve ter sido capturada e o pedido existente relido.');
        $this->assertSame(1, Order::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_x_idempotency_key_e_a_do_pedido_e_se_repete_em_nova_tentativa_de_pagamento(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'pending'));

        $response = $this->checkout($payload, (string) Str::uuid())->assertStatus(201);

        $order = Order::sole();
        $this->assertTrue(Str::isUuid((string) $order->payment_idempotency_key));
        $this->assertSame([$order->payment_idempotency_key], $this->sentIdempotencyHeaders());
        $this->assertSame($order->payment_idempotency_key, $response->json('payment.idempotency_key'));

        // Nova tentativa de criar o pagamento para o MESMO pedido reutiliza a mesma chave.
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'pending'));
        $dto = new CheckoutOrderDTO(
            customerName: 'Fernanda Souza Lima',
            customerEmail: 'fernanda@example.com',
            customerCpf: self::VALID_CPF_DIGITS,
            customerPhone: '11988887777',
            shippingAddress: $order->shipping_address,
            shippingCostCents: $order->shipping_cost_cents,
            items: [new CheckoutItemDTO('x', 'y', null, 1)],
            paymentMethod: 'credit_card',
            cardToken: 'card-token-retentativa',
            paymentMethodId: 'visa',
        );
        app(MercadoPagoService::class)->createCardPayment($order->fresh(), $dto);

        $this->assertSame([$order->payment_idempotency_key, $order->payment_idempotency_key], $this->sentIdempotencyHeaders());
    }

    public function test_fingerprint_ignora_card_token_e_ordem_das_chaves(): void
    {
        $a = ['b' => 1, 'a' => ['y' => 2, 'x' => 3], 'card_token' => 't1', 'payment' => ['card_token' => 't1', 'installments' => 1]];
        $b = ['payment' => ['installments' => 1, 'card_token' => 't2'], 'a' => ['x' => 3, 'y' => 2], 'b' => 1, 'card_token' => 't2'];
        $c = $b;
        $c['payment']['installments'] = 2;

        $this->assertSame(CheckoutIdempotencyService::fingerprint($a), CheckoutIdempotencyService::fingerprint($b));
        $this->assertNotSame(CheckoutIdempotencyService::fingerprint($a), CheckoutIdempotencyService::fingerprint($c));
        $this->assertSame(64, strlen(CheckoutIdempotencyService::fingerprint($a)));
    }
}
