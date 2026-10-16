<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-26, critério 7: "Meus pedidos" mostra só os pedidos do e-mail confirmado da conta.
 */
class CustomerOrdersTest extends CharacterizationTestCase
{
    public function test_sem_email_confirmado_da_403(): void
    {
        $customer = Customer::factory()->unverified()->create(['email' => 'ana@exemplo.com']);
        Order::factory()->create(['customer_email' => 'ana@exemplo.com']);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/conta/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified')
            ->assertJsonMissingPath('data');
    }

    public function test_confirmado_ve_os_pedidos_do_proprio_email_em_qualquer_caixa_e_nunca_os_de_outro(): void
    {
        $customer = Customer::factory()->create(['email' => 'ana@exemplo.com']);

        $old = Order::factory()->status(OrderStatus::SHIPPED)->create([
            'order_number' => 'MIMOO-ANTIGO',
            'customer_email' => ' Ana@Exemplo.COM ',
            'total_cents' => 8991,
            'created_at' => now()->subDays(10),
        ]);
        OrderItem::factory()->count(2)->create(['order_id' => $old->id]);
        Order::factory()->create([
            'order_number' => 'MIMOO-NOVO',
            'customer_email' => 'ana@exemplo.com',
            'created_at' => now()->subDay(),
        ]);
        Order::factory()->create(['order_number' => 'MIMOO-OUTRA', 'customer_email' => 'outra@exemplo.com']);
        Order::factory()->create(['order_number' => 'MIMOO-PARECIDO', 'customer_email' => 'xana@exemplo.com']);

        $response = $this->actingAs($customer, 'customer')->getJson('/api/conta/orders');

        $response->assertOk();
        $this->assertSame(['MIMOO-NOVO', 'MIMOO-ANTIGO'], array_column($response->json('data'), 'order_number'));

        $this->assertSame([
            'order_number' => 'MIMOO-ANTIGO',
            'status' => 'shipped',
            'status_label' => OrderStatus::SHIPPED->getLabel(),
            'created_at' => $old->created_at->toIso8601String(),
            'total_cents' => 8991,
            'items_count' => 2,
            'tracking_token' => $old->tracking_token,
        ], $response->json('data.1'));
    }

    public function test_resposta_nao_traz_cpf_telefone_email_nem_endereco(): void
    {
        $customer = Customer::factory()->create(['email' => 'ana@exemplo.com']);
        Order::factory()->create([
            'customer_email' => 'ana@exemplo.com',
            'customer_cpf' => '52998224725',
            'customer_phone' => '11988887777',
        ]);

        $body = $this->actingAs($customer, 'customer')->getJson('/api/conta/orders')->assertOk()->getContent();

        foreach (['52998224725', '11988887777', 'ana@exemplo.com', 'Praça da Sé', '01001000', 'customer_name', 'shipping_address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_conta_sem_pedidos_recebe_lista_vazia(): void
    {
        $this->actingAs(Customer::factory()->create(), 'customer')
            ->getJson('/api/conta/orders')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
