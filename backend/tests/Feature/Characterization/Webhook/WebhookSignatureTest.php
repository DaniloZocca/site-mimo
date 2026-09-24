<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * Camadas A–C do MercadoPagoWebhookController: cabeçalhos, anti-replay e HMAC.
 */
#[Group('characterization')]
class WebhookSignatureTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaidEvent::class]);
        $this->order = Order::factory()->create(['total_cents' => 10000]);
    }

    private function assertOrderUntouched(): void
    {
        $this->order->refresh();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $this->order->status);
        $this->assertNull($this->order->paid_at);
        $this->assertSame(0, $this->order->productionLogs()->count());
        $this->assertCount(0, $this->mpHttp->requests, 'Não deveria consultar a API do Mercado Pago.');
    }

    public function test_sem_cabecalhos_de_assinatura_retorna_401(): void
    {
        $this->postWebhook('123')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Assinatura ou ID de requisição ausente.']);

        $this->assertOrderUntouched();
    }

    public function test_com_x_signature_mas_sem_x_request_id_retorna_401(): void
    {
        $headers = $this->signedHeaders('123');
        unset($headers['x-request-id']);

        $this->postWebhook('123', $headers)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Assinatura ou ID de requisição ausente.']);

        $this->assertOrderUntouched();
    }

    public function test_x_signature_sem_ts_ou_v1_retorna_401_formato_invalido(): void
    {
        $this->postWebhook('123', ['x-signature' => 'assinatura-qualquer', 'x-request-id' => 'req-1'])
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Formato de assinatura inválido.']);

        $this->assertOrderUntouched();
    }

    public function test_hmac_invalido_retorna_401(): void
    {
        $headers = $this->signedHeaders('123');
        $headers['x-signature'] = 'ts='.time().',v1='.str_repeat('0', 64);

        $this->postWebhook('123', $headers)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Assinatura criptográfica inválida.']);

        $this->assertOrderUntouched();
    }

    public function test_hmac_assinado_com_outro_segredo_retorna_401(): void
    {
        $headers = $this->signedHeaders('123', secret: 'segredo-errado');

        $this->postWebhook('123', $headers)->assertStatus(401);

        $this->assertOrderUntouched();
    }

    public function test_hmac_de_outro_data_id_retorna_401(): void
    {
        // Assinatura válida para o id 999 reaproveitada numa notificação do id 123.
        $this->postWebhook('123', $this->signedHeaders('999'))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Assinatura criptográfica inválida.']);

        $this->assertOrderUntouched();
    }

    public function test_timestamp_301s_no_passado_retorna_401_expirado(): void
    {
        // travelTo não se aplica: o controller usa time() nativo (ver SendsMercadoPagoWebhooks).
        $this->postWebhook('123', $this->signedHeaders('123', ts: time() - 301))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Timestamp expirado.']);

        $this->assertOrderUntouched();
    }

    public function test_timestamp_de_um_dia_atras_retorna_401_expirado(): void
    {
        $this->postWebhook('123', $this->signedHeaders('123', ts: time() - 86400))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Timestamp expirado.']);
    }

    public function test_timestamp_295s_no_passado_e_aceito(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postWebhook('123', $this->signedHeaders('123', ts: time() - 295))->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_timestamp_295s_no_futuro_e_aceito_porque_a_janela_usa_abs(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postWebhook('123', $this->signedHeaders('123', ts: time() + 295))->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_timestamp_301s_no_futuro_retorna_401(): void
    {
        $this->postWebhook('123', $this->signedHeaders('123', ts: time() + 301))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Timestamp expirado.']);
    }

    public function test_timestamp_em_milissegundos_e_rejeitado_como_expirado(): void
    {
        // O código compara ts em SEGUNDOS; um ts em milissegundos cai fora da janela.
        $this->postWebhook('123', $this->signedHeaders('123', ts: time() * 1000))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthorized: Timestamp expirado.']);
    }

    public function test_assinatura_valida_processa_a_notificacao(): void
    {
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Pagamento aprovado e pedido encaminhado para a fila de manufatura 3D.',
                'order_status' => 'in_queue',
            ]);

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_sem_segredo_configurado_aceita_requisicao_sem_nenhum_cabecalho(): void
    {
        // BUG CONHECIDO: webhook fail-open quando MERCADOPAGO_WEBHOOK_SECRET está vazio — ver teste gêmeo em KnownBugs
        $this->setWebhookSecret('');
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postWebhook('123')->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }

    public function test_sem_segredo_configurado_aceita_assinatura_lixo(): void
    {
        // BUG CONHECIDO: webhook fail-open quando MERCADOPAGO_WEBHOOK_SECRET está vazio — ver teste gêmeo em KnownBugs
        $this->setWebhookSecret('');
        $this->apiReturnsPayment(123, 'approved', $this->order);

        $this->postWebhook('123', ['x-signature' => 'lixo', 'x-request-id' => 'x'])->assertOk();

        $this->assertSame(OrderStatus::IN_QUEUE, $this->order->refresh()->status);
    }
}
