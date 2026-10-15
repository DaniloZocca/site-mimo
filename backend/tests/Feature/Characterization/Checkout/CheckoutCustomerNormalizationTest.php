<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Events\OrderPaid;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * M-19: CPF, telefone e CEP chegam ao pedido gravado só com dígitos; telefone e CEP são
 * validados pelos dígitos (10/11 e 8), como no formulário da loja.
 */
#[Group('characterization')]
class CheckoutCustomerNormalizationTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([OrderPaid::class, OrderPaidEvent::class]);
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $shipping
     * @return array<string, mixed>
     */
    private function payloadWith(array $customer = [], array $shipping = []): array
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $payload['customer'] = array_merge($payload['customer'], $customer);
        $payload['shipping'] = array_merge($payload['shipping'], $shipping);

        return $payload;
    }

    public function test_dados_com_mascara_sao_gravados_so_com_digitos(): void
    {
        $payload = $this->payloadWith(
            ['cpf' => self::VALID_CPF, 'phone' => '(11) 98888-7777'],
            ['zip_code' => '01001-000', 'state' => 'sp'],
        );
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $order = Order::sole();
        $this->assertSame(self::VALID_CPF_DIGITS, $order->customer_cpf);
        $this->assertSame('11988887777', $order->customer_phone);
        $this->assertSame('01001000', $order->shipping_address['zip_code']);
        $this->assertSame('SP', $order->shipping_address['state']);
    }

    public function test_dados_ja_limpos_sao_gravados_iguais(): void
    {
        $payload = $this->payloadWith(
            ['cpf' => self::VALID_CPF_DIGITS, 'phone' => '1133334444'],
            ['zip_code' => '01001000', 'state' => 'SP'],
        );
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $order = Order::sole();
        $this->assertSame(self::VALID_CPF_DIGITS, $order->customer_cpf);
        $this->assertSame('1133334444', $order->customer_phone);
        $this->assertSame('01001000', $order->shipping_address['zip_code']);
    }

    public function test_telefone_com_poucos_digitos_e_recusado(): void
    {
        $payload = $this->payloadWith(['phone' => 'tel: (11) 9']);

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errors): bool => $errors['customer.phone'] === ['Informe um telefone/celular com DDD válido (10 ou 11 dígitos).']);

        $this->assertSame(0, Order::count());
        $this->assertCount(0, $this->mpHttp->requests);
    }

    public function test_telefone_com_doze_digitos_e_recusado(): void
    {
        $payload = $this->payloadWith(['phone' => '+55 11 9888-87777']);

        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonValidationErrors(['customer.phone']);
        $this->assertSame(0, Order::count());
    }

    public function test_cep_com_espaco_e_aceito_e_gravado_limpo(): void
    {
        $payload = $this->payloadWith([], ['zip_code' => '01001 000']);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending'));

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        $this->assertSame('01001000', Order::sole()->shipping_address['zip_code']);
    }

    public function test_cep_com_sete_digitos_e_recusado(): void
    {
        $payload = $this->payloadWith([], ['zip_code' => '01001-00']);

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errors): bool => $errors['shipping.zip_code'] === ['O CEP deve conter exatamente 8 dígitos numéricos.']);

        $this->assertSame(0, Order::count());
    }

    public function test_campos_que_nao_sao_texto_dao_422_sem_erro_500(): void
    {
        $payload = $this->payloadWith(
            ['cpf' => 52998224725, 'phone' => ['11988887777']],
            ['zip_code' => ['01001000']],
        );

        $this->postJson('/api/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer.cpf', 'customer.phone', 'shipping.zip_code']);

        $this->assertSame(0, Order::count());
    }

    public function test_replay_com_mascara_devolve_a_mesma_resposta_sem_novo_pedido(): void
    {
        $payload = $this->payloadWith(['phone' => '(11) 98888-7777'], ['zip_code' => '01001-000']);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(9001, 'pending'));
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key])->assertStatus(201);
        $second = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => $key])
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Order::count());
        $this->assertCount(1, $this->mpHttp->requests);
    }
}
