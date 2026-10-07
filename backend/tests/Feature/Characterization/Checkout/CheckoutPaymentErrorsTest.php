<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\OrderStatus;
use App\Mail\OrderConfirmedMail;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-08: falha do gateway (502, pedido pendente), cartão recusado (422) e cartão aprovado com e-mail de confirmação.
 * Sem Queue::fake(): os listeners rodam na fila sync, como no teste de e-mail do webhook.
 */
#[Group('characterization')]
class CheckoutPaymentErrorsTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;
    use SendsMercadoPagoWebhooks;

    protected bool $fakeQueue = false;

    private const CUSTOMER_EMAIL = 'fernanda@example.com';

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkout(array $payload, string $key): TestResponse
    {
        return $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key]);
    }

    public function test_reenvio_apos_falha_do_gateway_devolve_o_mesmo_502_sem_nova_chamada(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out'));
        $key = (string) Str::uuid();

        $first = $this->checkout($payload, $key)->assertStatus(502);
        $second = $this->checkout($payload, $key)->assertStatus(502)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, Order::sole()->status);
    }

    public function test_reenvio_apos_recusa_devolve_o_mesmo_422_sem_nova_chamada(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(778, 'rejected', statusDetail: 'cc_rejected_bad_filled_security_code'));
        $key = (string) Str::uuid();

        $first = $this->checkout($payload, $key)->assertStatus(422);
        $second = $this->checkout($payload, $key)->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame('Código de segurança (CVV) incorreto.', $second->json('message'));
        $this->assertCount(1, $this->mpHttp->requests);
    }

    public function test_webhook_aprovado_depois_de_falha_do_gateway_libera_o_pedido(): void
    {
        // Timeout no checkout, mas a cobrança foi criada no Mercado Pago: o webhook encontra o pedido aberto.
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('cURL error 28: Operation timed out'));
        $this->postJson('/api/checkout', $payload)->assertStatus(502);
        $order = Order::sole();

        $this->apiReturnsPayment(555, 'approved', $order);
        $this->postSignedWebhook('555')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertMailCount(1);
    }

    public function test_webhook_recusado_depois_de_recusa_no_checkout_cancela_o_pedido(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(778, 'rejected', statusDetail: 'cc_rejected_insufficient_amount'));
        $this->postJson('/api/checkout', $payload)->assertStatus(422);
        $order = Order::sole();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);

        $this->apiReturnsPayment(778, 'rejected', $order);
        $this->postSignedWebhook('778')->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertMailCount(0);
    }

    public function test_cartao_aprovado_envia_um_email_de_confirmacao_mesmo_com_o_webhook_depois(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(777, 'approved'));
        $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $order = Order::sole();
        $this->assertSame(OrderStatus::IN_QUEUE, $order->status);
        $this->assertMailCount(1);

        // Webhook do mesmo pagamento chega depois: caminho idempotente, sem segundo e-mail.
        $this->apiReturnsPayment(777, 'approved', $order);
        $this->postSignedWebhook('777')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $order->fresh()->status);
        $this->assertMailCount(1);
    }

    private function assertMailCount(int $expected): void
    {
        $count = Mail::sent(OrderConfirmedMail::class, fn ($m): bool => $m->hasTo(self::CUSTOMER_EMAIL))->count()
            + Mail::queued(OrderConfirmedMail::class, fn ($m): bool => $m->hasTo(self::CUSTOMER_EMAIL))->count();

        $this->assertSame($expected, $count);
    }
}
