<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Events\OrderPaidEvent;
use App\Jobs\ProcessOrderPaidNotification;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * POST /api/checkout — caminhos felizes PIX e cartão.
 */
#[Group('characterization')]
class CheckoutHappyPathTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const ORDER_NUMBER_REGEX = '/^MIMOO-[0-9A-HJKMNP-TV-Z]{8}$/';

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaid::class, OrderPaidEvent::class]);
    }

    public function test_pix_caminho_feliz_resposta_201_formato_exato_e_pedido_pendente(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending', statusDetail: 'pending_waiting_transfer'));

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        // 5000 − round(5000 × 0,10) + frete do servidor 1490 (PAC, fallback SP) = 5990
        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Pedido gerado com sucesso!')
            ->whereType('order_id', 'string')
            ->where('order_number', fn (string $v): bool => (bool) preg_match(self::ORDER_NUMBER_REGEX, $v))
            ->where('tracking_token', fn (string $v): bool => (bool) preg_match(self::UUID_REGEX, $v))
            ->where('status', 'pending_payment')
            ->where('status_label', 'Aguardando Pagamento')
            ->where('total_cents', 5990)
            ->where('total_formatted', 'R$ 59,90')
            ->has('payment', fn (AssertableJson $p) => $p
                ->where('method', 'pix')
                ->where('payment_id', '9001')
                ->where('status', 'pending')
                ->where('status_detail', 'pending_waiting_transfer')
                ->where('qr_code', '00020126PIXCOPIAECOLA')
                ->where('qr_code_base64', 'iVBORw0KGgo=')
                ->where('ticket_url', 'https://www.mercadopago.com.br/payments/9001/ticket')
                ->where('idempotency_key', fn (string $v): bool => (bool) preg_match(self::UUID_REGEX, $v))
            )
        );

        $order = Order::sole();
        $this->assertSame($response->json('order_id'), $order->id);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame(5990, $order->total_cents);
        $this->assertSame(self::SERVER_PAC_CENTS, $order->shipping_cost_cents);
        $this->assertNull($order->paid_at);
        // PERGUNTA: passedValidation() sanitiza CPF/telefone/CEP com merge(), mas o DTO lê
        // $request->validated(), que não recebe o merge. Resultado: os valores são gravados
        // exatamente como o cliente digitou (com máscara). Só o UF é normalizado (no DTO).
        $this->assertSame(self::VALID_CPF, $order->customer_cpf);
        $this->assertSame('(11) 98888-7777', $order->customer_phone);
        $this->assertSame([
            'zip_code' => '01001-000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'complement' => 'Apto 12',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $order->shipping_address);

        $log = $order->productionLogs()->sole();
        $this->assertSame('checkout', $log->previous_status);
        $this->assertSame('pending_payment', $log->new_status);
        $this->assertSame('Pedido persistido com snapshots e valores auditados pelo backend (Desconto PIX 10% aplicado: R$ 5,00).', $log->notes);

        Event::assertNotDispatched(OrderPaid::class);
        Queue::assertNotPushed(ProcessOrderPaidNotification::class);
    }

    public function test_pix_envia_payload_e_chave_de_idempotencia_ao_mercado_pago(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending'));

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $request = $this->mpHttp->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/payments', $request->getUri());
        $this->assertContains('X-Idempotency-Key: '.$response->json('payment.idempotency_key'), $request->getHeaders());
        $this->assertContains('Authorization: Bearer '.self::ACCESS_TOKEN, $request->getHeaders());

        $orderNumber = $response->json('order_number');
        $this->assertSame([
            'transaction_amount' => 59.9,
            'description' => "MIMOO LAB - Encomenda #{$orderNumber}",
            'payment_method_id' => 'pix',
            'external_reference' => $orderNumber,
            'notification_url' => 'http://localhost/api/webhooks/mercadopago',
            'payer' => [
                'email' => 'fernanda@example.com',
                'first_name' => 'Fernanda Souza Lima',
                'identification' => ['type' => 'CPF', 'number' => self::VALID_CPF_DIGITS],
            ],
        ], $this->mpHttp->lastPayload());
        // PERGUNTA: o PIX não envia date_of_expiration (a skill de pagamentos pede expiração explícita).
    }

    public function test_pix_em_modo_simulado_nao_chama_o_gateway(): void
    {
        // Com access token vazio ou contendo TEST-000000, createPixPayment devolve um PIX falso.
        config(['services.mercadopago.access_token' => 'TEST-000000']);
        ['payload' => $payload] = $this->simpleCheckout('pix');

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $this->assertCount(0, $this->mpHttp->requests);
        $this->assertStringStartsWith('pix_dev_', $response->json('payment.payment_id'));
        $this->assertSame('pending', $response->json('payment.status'));
        $this->assertSame('pending_waiting_transfer', $response->json('payment.status_detail'));
        $this->assertStringStartsWith('00020126580014br.gov.bcb.pix0136', $response->json('payment.qr_code'));
        $this->assertSame('', $response->json('payment.qr_code_base64'));
        $this->assertSame('https://pix.bcb.gov.br', $response->json('payment.ticket_url'));
    }

    public function test_cartao_aprovado_vai_para_in_queue_com_log_evento_e_job(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        // Cartão: sem desconto. 5000 + frete do servidor 1490 = 6490
        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Pedido gerado com sucesso!')
            ->whereType('order_id', 'string')
            ->whereType('order_number', 'string')
            ->whereType('tracking_token', 'string')
            ->where('status', 'in_queue')
            ->where('status_label', 'Na Fila de Impressão 3D')
            ->where('total_cents', 6490)
            ->where('total_formatted', 'R$ 64,90')
            ->has('payment', fn (AssertableJson $p) => $p
                ->where('method', 'credit_card')
                ->where('payment_id', '777')
                ->where('status', 'approved')
                ->where('status_detail', 'accredited')
                ->where('qr_code', null)
                ->where('qr_code_base64', null)
                ->where('ticket_url', null)
                ->whereType('idempotency_key', 'string')
            )
        );

        $order = Order::sole();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);
        $this->assertSame(self::FROZEN_NOW, $order->paid_at?->format('Y-m-d H:i:s'));

        // Os dois logs têm o mesmo created_at (tempo congelado); compara como conjunto.
        $pairs = $order->productionLogs()->get()
            ->map(fn ($l): string => "{$l->previous_status}->{$l->new_status}")
            ->sort()->values()->all();
        $this->assertSame(['checkout->pending_payment', 'pending_payment->in_queue'], $pairs);
        $this->assertTrue($order->productionLogs()->where('notes', 'Pagamento autorizado instantaneamente via cartão (ID: 777)')->exists());

        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->order->is($order));
        Queue::assertPushed(ProcessOrderPaidNotification::class, 1);
    }

    public function test_cartao_aprovado_dispara_order_paid_sem_listener_e_nao_order_paid_event(): void
    {
        // BUG CONHECIDO: aprovação por cartão dispara OrderPaid (sem listener) em vez de OrderPaidEvent — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        Event::assertDispatched(OrderPaid::class);
        Event::assertNotDispatched(OrderPaidEvent::class);
    }

    public function test_cartao_envia_token_parcelas_e_bandeira_ao_mercado_pago(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $payload['payment']['installments'] = 3;
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $orderNumber = $response->json('order_number');
        $this->assertSame([
            'transaction_amount' => 64.9,
            'token' => 'card-token-characterization',
            'installments' => 3,
            'payment_method_id' => 'visa',
            'description' => "MIMOO LAB - Encomenda #{$orderNumber}",
            'external_reference' => $orderNumber,
            'notification_url' => 'http://localhost/api/webhooks/mercadopago',
            'payer' => [
                'email' => 'fernanda@example.com',
                'identification' => ['type' => 'CPF', 'number' => self::VALID_CPF_DIGITS],
            ],
        ], $this->mpHttp->lastPayload());
    }

    public function test_cartao_rejeitado_responde_201_e_pedido_fica_pendente(): void
    {
        // BUG CONHECIDO: cartão rejected devolve 201 e o pedido fica pending_payment para sempre — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(778, 'rejected', statusDetail: 'cc_rejected_insufficient_amount'));

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(201)
            ->assertJsonPath('message', 'Pedido gerado com sucesso!')
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('payment.status', 'rejected')
            ->assertJsonPath('payment.status_detail', 'cc_rejected_insufficient_amount');

        $order = Order::sole();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame(1, $order->productionLogs()->count());
    }

    public function test_payload_consolidado_do_frontend_tambem_e_aceito(): void
    {
        // prepareForValidation mapeia shipping_address/payment_method/card_token da raiz.
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));

        $this->postJson('/api/checkout', [
            'customer' => ['name' => 'Fernanda Souza', 'email' => 'f@example.com', 'cpf' => self::VALID_CPF, 'phone' => '11988887777'],
            'shipping_address' => ['cep' => '01001-000', 'street' => 'Praça da Sé', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP'],
            'shipping_service' => 'SEDEX',
            'shipping_cost_cents' => 2290, // SEDEX do fallback SP, recalculado no servidor
            'payment_method' => 'credit_card',
            'card_token' => 'tok',
            'installments' => 2,
            'payment_method_id' => 'master',
            'items' => [$this->itemPayload($product, $color)],
        ])->assertStatus(201)->assertJsonPath('total_cents', 7290);

        $this->assertSame('01001-000', Order::sole()->shipping_address['zip_code']);
        // service_name ('SEDEX') não é persistido no shipping_address.
        $this->assertArrayNotHasKey('service_name', Order::sole()->shipping_address);
    }
}
