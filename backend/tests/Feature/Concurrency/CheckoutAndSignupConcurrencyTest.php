<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Models\Customer;
use App\Models\Order;
use App\Services\CheckoutIdempotencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Concurrency\Support\ConcurrencyTestCase;

/**
 * M-45: duplicatas decididas pelo índice UNIQUE (checkout com a mesma Idempotency-Key, cadastro com o mesmo
 * e-mail) quando os envios chegam juntos, cada um no seu processo. O que o teste segura é a linha recém-inserida
 * e ainda não commitada: os outros ficam esperando o índice decidir.
 */
#[Group('concurrency')]
class CheckoutAndSignupConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsCheckoutPayload;

    public function test_checkout_repetido_enquanto_o_primeiro_envio_esta_em_andamento_responde_409_sem_cobrar(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->simpleCheckout()['payload'];

        // O primeiro envio inseriu o pedido e ainda não commitou (nem chamou o Mercado Pago)
        DB::beginTransaction();
        Order::factory()->create([
            'idempotency_key' => $key,
            'request_fingerprint' => CheckoutIdempotencyService::fingerprint($payload),
            'payment_idempotency_key' => (string) Str::uuid(),
        ]);

        $second = $this->startWorker('checkout', ['key' => $key, 'payload' => $payload, 'payment_id' => 880001]);
        $third = $this->startWorker('checkout', ['key' => $key, 'payload' => $payload, 'payment_id' => 880002]);
        $this->assertWaitingForLock($second, $third);
        DB::commit();

        foreach ([$this->resultOf($second), $this->resultOf($third)] as $result) {
            $this->assertSame(409, $result['status']);
            $this->assertSame('request_in_progress', $result['body']['code']);
            $this->assertSame(0, $result['mp_calls']);
        }
        $this->assertSame(1, Order::where('idempotency_key', $key)->count());
    }

    public function test_checkout_repetido_quando_o_primeiro_envio_desiste_cria_um_pedido_e_cobra_uma_vez(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->simpleCheckout()['payload'];

        // O primeiro envio inseriu o pedido e desistiu (rollback): os dois seguintes disputam a mesma chave
        DB::beginTransaction();
        Order::factory()->create(['idempotency_key' => $key, 'request_fingerprint' => CheckoutIdempotencyService::fingerprint($payload)]);

        $second = $this->startWorker('checkout', ['key' => $key, 'payload' => $payload, 'payment_id' => 880001]);
        $third = $this->startWorker('checkout', ['key' => $key, 'payload' => $payload, 'payment_id' => 880002]);
        $this->assertWaitingForLock($second, $third);
        DB::rollBack();

        $results = [$this->resultOf($second), $this->resultOf($third)];
        usort($results, fn (array $a, array $b): int => $b['mp_calls'] <=> $a['mp_calls']);
        [$winner, $other] = $results;

        $this->assertSame(201, $winner['status'], json_encode($winner) ?: '');
        $this->assertSame(1, $winner['mp_calls']);
        $this->assertSame(0, $other['mp_calls']);
        $this->assertContains($other['status'], [201, 409], json_encode($other) ?: '');
        if ($other['status'] === 201) {
            $this->assertSame($winner['body'], $other['body'], 'O replay devolve a mesma resposta.');
        }
        $this->assertSame(1, Order::where('idempotency_key', $key)->count());
        $this->assertSame(1, Order::where('idempotency_key', $key)->firstOrFail()->payments()->count());
    }

    public function test_dois_cadastros_com_o_mesmo_email_ao_mesmo_tempo_criam_uma_conta_so(): void
    {
        $email = 'cliente-concorrencia@exemplo.com';

        DB::beginTransaction();
        Customer::factory()->create(['email' => $email]);

        $first = $this->startWorker('register', $this->signup($email));
        $second = $this->startWorker('register', $this->signup($email));
        $this->assertWaitingForLock($first, $second);
        DB::commit();

        $this->assertSame([202, 202], [$this->resultOf($first)['status'], $this->resultOf($second)['status']]);
        $this->assertSame(1, Customer::where('email', $email)->count());
    }

    public function test_dois_cadastros_com_o_mesmo_email_quando_o_primeiro_desiste_criam_uma_conta_so(): void
    {
        $email = 'cliente-concorrencia@exemplo.com';

        DB::beginTransaction();
        Customer::factory()->create(['email' => $email]);

        $first = $this->startWorker('register', $this->signup($email));
        $second = $this->startWorker('register', $this->signup($email));
        $this->assertWaitingForLock($first, $second);
        DB::rollBack();

        $this->assertSame([202, 202], [$this->resultOf($first)['status'], $this->resultOf($second)['status']]);
        $this->assertSame(1, Customer::where('email', $email)->count());
    }

    /**
     * @return array<string, string>
     */
    private function signup(string $email): array
    {
        return ['name' => 'Cliente Teste', 'email' => $email, 'password' => 'senha12345', 'password_confirmation' => 'senha12345'];
    }
}
