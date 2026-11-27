<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Events\OrderPaid;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-06 (S11 da auditoria de 2026-11-27): o servidor aceita no máximo 6 parcelas, o mesmo teto do formulário
 * do cartão (`CreditCardPaymentStep.jsx`, `maxInstallments: 6`). Antes aceitava 12: quem chamasse a API direto
 * escolhia 12x, com os juros por conta da loja se a conta do Mercado Pago estiver configurada assim.
 */
class CheckoutInstallmentsLimitTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private const LIMIT_MESSAGE = 'O parcelamento no cartão vai até 6x.';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaid::class, OrderPaidEvent::class]);
    }

    public function test_cartao_com_7_parcelas_responde_422_sem_pedido_e_sem_chamar_o_mercado_pago(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $payload['payment']['installments'] = 7;

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors', ['payment.installments' => [self::LIMIT_MESSAGE]])
            ->assertJsonPath('message', self::LIMIT_MESSAGE);

        $this->assertSame(0, Order::count());
        $this->assertSame([], $this->mpHttp->requests);
    }

    public function test_cartao_com_12_parcelas_tambem_e_recusado(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $payload['payment']['installments'] = 12;

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(422);

        $this->assertSame([self::LIMIT_MESSAGE], $response->json('errors')['payment.installments'] ?? null);
        $this->assertSame([], $this->mpHttp->requests);
    }

    public function test_cartao_com_6_parcelas_e_aprovado_e_o_mercado_pago_recebe_6(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $payload['payment']['installments'] = 6;
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(7006, 'approved'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $this->assertSame(6, $this->mpHttp->lastPayload()['installments'] ?? null);
        $this->assertSame(1, Order::count());
    }

    public function test_cartao_com_0_parcelas_continua_recusado(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $payload['payment']['installments'] = 0;

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment.installments']);

        $this->assertSame([], $this->mpHttp->requests);
    }
}
