<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Checkout;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ShippingController;
use App\Models\Order;
use App\Services\OrderCreationService;
use App\Services\PaymentApprovalValidator;
use App\Services\ShippingCalculationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-46: erro inesperado no checkout e no frete não mostra nem loga dados pessoais.
 * A resposta tem texto fixo + código curto (error_ref); o log tem o mesmo código, a classe, o SQLSTATE e o
 * código do driver, e o primeiro arquivo:linha em app/. Nunca a mensagem, o SQL, os bindings ou o trace.
 */
#[Group('characterization')]
class CheckoutUnexpectedErrorPrivacyTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;
    use SendsMercadoPagoWebhooks;

    private const EMAIL = 'fulana.segredo@exemplo.com';

    private const CPF = '52998224725';

    private const CEP = '01310930';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    public function test_erro_de_banco_no_checkout_responde_texto_fixo_com_codigo_e_sem_dados_pessoais(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->partialMock(OrderCreationService::class, function ($mock): void {
            $mock->shouldReceive('createOrder')->andThrow($this->queryException(QueryException::class, '22001', 1406,
                "Data too long for column 'customer_email' at row 1"));
        });

        $response = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertStatus(422)->assertJsonPath('code', CheckoutController::UNEXPECTED_ERROR_CODE);
        $ref = (string) $response->json('error_ref');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $ref);
        $this->assertSame(CheckoutController::UNEXPECTED_ERROR_MESSAGE." (código do erro: {$ref})", $response->json('message'));
        $this->assertSame(['code', 'message', 'error_ref'], array_keys($response->json()));
        $this->assertNoSensitiveData($response->getContent());

        $this->assertSame(0, Order::count());
        $this->assertCount(0, $this->mpHttp->requests);

        $entry = $this->sole('Erro inesperado ao criar o pedido no checkout.');
        $this->assertSame('error', $entry['level']);
        $this->assertSame($ref, $entry['context']['error_ref']);
        $this->assertSame(QueryException::class, $entry['context']['exception']);
        $this->assertSame('22001', $entry['context']['sqlstate']);
        $this->assertSame(1406, $entry['context']['driver_code']);
        $this->assertNoSensitiveData(json_encode($this->logged));
    }

    public function test_duplicate_entry_de_outro_indice_responde_texto_fixo_e_loga_o_codigo_1062(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->partialMock(OrderCreationService::class, function ($mock): void {
            $mock->shouldReceive('createOrder')->andThrow($this->queryException(UniqueConstraintViolationException::class, '23000', 1062,
                "Duplicate entry '".self::EMAIL."' for key 'customers_email_unique'"));
        });

        $response = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertStatus(422)->assertJsonPath('code', CheckoutController::UNEXPECTED_ERROR_CODE);
        $ref = (string) $response->json('error_ref');
        $this->assertNoSensitiveData($response->getContent());

        $entry = $this->sole('Erro inesperado ao criar o pedido no checkout.');
        $this->assertSame($ref, $entry['context']['error_ref']);
        $this->assertSame(UniqueConstraintViolationException::class, $entry['context']['exception']);
        $this->assertSame('23000', $entry['context']['sqlstate']);
        $this->assertSame(1062, $entry['context']['driver_code']);
        $this->assertStringStartsWith('app/', (string) $entry['context']['location']);
        $this->assertNoSensitiveData(json_encode($this->logged));
    }

    public function test_codigo_do_erro_e_diferente_a_cada_erro(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('pix');
        $this->partialMock(OrderCreationService::class, function ($mock): void {
            $mock->shouldReceive('createOrder')->andThrow($this->queryException(QueryException::class, '40001', 1213, 'Deadlock found'));
        });

        $first = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
        $second = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

        $this->assertNotSame($first->json('error_ref'), $second->json('error_ref'));
    }

    public function test_erro_de_validacao_do_catalogo_continua_igual_sem_codigo_novo(): void
    {
        ['product' => $product] = $this->catalogItem();
        ['color' => $otherColor] = $this->catalogItem();
        $payload = $this->checkoutPayload([$this->itemPayload($product, $otherColor)]);

        $response = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.color_id']);
        $this->assertNull($response->json('code'));
        $this->assertNull($response->json('error_ref'));
    }

    public function test_falha_do_gateway_com_dado_pessoal_na_mensagem_nao_vai_para_o_log(): void
    {
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new RuntimeException('payer.email '.self::EMAIL.' cpf '.self::CPF.' recusado'));

        $response = $this->postJson('/api/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertStatus(502);
        $this->assertNoSensitiveData($response->getContent());
        $order = Order::sole();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);

        $entry = $this->sole("Falha ao processar pagamento do pedido #{$order->order_number}.");
        $this->assertSame($order->order_number, $entry['context']['order_number']);
        $this->assertNotEmpty($this->logged);
        $this->assertNoSensitiveData(json_encode($this->logged));
        $this->assertStringNotContainsString('recusado', json_encode($this->logged));
    }

    public function test_erro_inesperado_no_frete_responde_texto_fixo_com_codigo_e_sem_o_cep(): void
    {
        ['product' => $product] = $this->catalogItem();
        $this->partialMock(ShippingCalculationService::class, function ($mock): void {
            $mock->shouldReceive('calculate')->andThrow($this->queryException(QueryException::class, 'HY000', 2006,
                'MySQL server has gone away'));
        });

        $response = $this->postJson('/api/shipping/calculate', [
            'destination_cep' => self::CEP,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $ref = (string) $response->json('error_ref');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $ref);
        $this->assertSame(ShippingController::UNEXPECTED_ERROR_MESSAGE." (código do erro: {$ref})", $response->json('message'));
        $this->assertNoSensitiveData($response->getContent());

        $entry = $this->sole('Erro inesperado ao calcular o frete.');
        $this->assertSame($ref, $entry['context']['error_ref']);
        $this->assertSame('HY000', $entry['context']['sqlstate']);
        $this->assertSame(2006, $entry['context']['driver_code']);
        $this->assertNoSensitiveData(json_encode($this->logged));
    }

    public function test_cep_invalido_no_frete_continua_com_a_mensagem_de_validacao(): void
    {
        $this->postJson('/api/shipping/calculate', ['destination_cep' => '123', 'items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination_cep'])
            ->assertJsonMissingPath('error_ref');
    }

    public function test_erro_de_banco_no_webhook_responde_500_igual_e_loga_sem_dados_pessoais(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::PENDING_PAYMENT]);
        $this->apiReturnsPayment(9101, 'approved', $order);
        $this->partialMock(PaymentApprovalValidator::class, function ($mock): void {
            $mock->shouldReceive('validate')->andThrow($this->queryException(QueryException::class, '23000', 1062,
                "Duplicate entry '".self::EMAIL."' for key 'x'"));
        });

        $this->postSignedWebhook('9101')->assertStatus(500);

        $entry = $this->sole("❌ Exceção ao processar webhook para #{$order->order_number}.");
        $this->assertSame('23000', $entry['context']['sqlstate']);
        $this->assertSame(1062, $entry['context']['driver_code']);
        $this->assertArrayNotHasKey('trace', $entry['context']);
        $this->assertNoSensitiveData(json_encode($this->logged));
    }

    /**
     * @param  class-string<QueryException>  $class
     */
    private function queryException(string $class, string $sqlState, int $driverCode, string $driverMessage): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$driverCode} {$driverMessage}");
        $pdo->errorInfo = [$sqlState, $driverCode, $driverMessage];

        return new $class(
            'mariadb',
            'insert into `orders` (`customer_email`, `customer_cpf`, `shipping_zip_code`) values (?, ?, ?)',
            [self::EMAIL, self::CPF, self::CEP],
            $pdo,
        );
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function sole(string $message): array
    {
        $matches = array_values(array_filter($this->logged, fn (array $entry): bool => $entry['message'] === $message));
        $this->assertCount(1, $matches, "Esperava uma linha de log '{$message}'. Logado: ".json_encode(array_column($this->logged, 'message')));

        return $matches[0];
    }

    private function assertNoSensitiveData(string|false $text): void
    {
        $text = (string) $text;
        foreach ([self::EMAIL, self::CPF, self::CEP, 'insert into', 'SQLSTATE[', 'Duplicate entry', 'customer_email', '#0 '] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }
}
