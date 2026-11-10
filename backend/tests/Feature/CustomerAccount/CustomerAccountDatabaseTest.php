<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * M-26 no banco de verdade (MariaDB no CI): sessão gravada em `sessions` com o UUID do cliente
 * (a coluna era bigint e dava 500) e "Meus pedidos" com lower(trim(customer_email)).
 */
class CustomerAccountDatabaseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_meus_pedidos_com_sessao_em_banco_grava_o_uuid_do_cliente_e_acha_o_pedido(): void
    {
        config(['session.driver' => 'database']);

        $customer = Customer::factory()->create(['email' => 'pg-'.uniqid().'@exemplo.com']);
        $order = Order::factory()->create(['customer_email' => '  '.strtoupper($customer->email).' ']);
        Order::factory()->create(['customer_email' => 'outra-'.uniqid().'@exemplo.com']);

        $response = $this->actingAs($customer, 'customer')->getJson('/api/conta/orders');

        $response->assertOk();
        $this->assertSame([$order->order_number], array_column($response->json('data'), 'order_number'));
        $this->assertDatabaseHas('sessions', ['user_id' => $customer->getKey()]);
    }
}
