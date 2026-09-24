<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Tracking;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionLog;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;

/**
 * Conteúdo da resposta do rastreio (OrderTrackingResource): formato, privacidade e timeline.
 */
#[Group('characterization')]
class TrackingResponseTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    private function trackByToken(Order $order): TestResponse
    {
        return $this->postJson('/api/orders/track', ['tracking_token' => $order->tracking_token])->assertOk();
    }

    public function test_formato_exato_da_resposta(): void
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create([
            'order_number' => 'MIMOO-ABC12345',
            'carrier_tracking_code' => 'BR123456789BR',
            'paid_at' => Carbon::parse('2026-09-22 10:30:00'),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_title_snapshot' => 'Dragão Articulado',
            'color_name_snapshot' => 'Roxo MIMOO',
            'size_name_snapshot' => 'Grande (18cm)',
            'material_name_snapshot' => 'PETG Resistente (Uso Externo & Térmico)',
            'unit_price_cents' => 9999,
            'quantity' => 2,
        ]);

        $this->trackByToken($order)->assertExactJson([
            'order_number' => 'MIMOO-ABC12345',
            'tracking_token' => $order->tracking_token,
            'customer_first_name' => 'Fernanda',
            'destination' => 'São Paulo - SP',
            'status' => 'shipped',
            'status_label' => 'Expedido / Em Trânsito',
            'status_color' => 'success',
            'status_icon' => 'heroicon-m-truck',
            'carrier_tracking_code' => 'BR123456789BR',
            'created_at' => '23/09/2026 12:00',
            'paid_at' => '22/09/2026 10:30',
            'items' => [[
                'title' => 'Dragão Articulado',
                'color' => 'Roxo MIMOO',
                'size' => 'Grande (18cm)',
                'material' => 'PETG Resistente (Uso Externo & Térmico)',
                'quantity' => 2,
            ]],
            'timeline' => [],
        ]);
    }

    public function test_nao_vaza_dados_pessoais(): void
    {
        $order = Order::factory()->create([
            'customer_name' => 'Fernanda Souza Lima',
            'customer_email' => 'fernanda.lima@example.com',
            'customer_cpf' => '529.982.247-25',
            'customer_phone' => '(11) 98888-7777',
        ]);

        $body = $this->trackByToken($order)->getContent();

        foreach (['fernanda.lima', '@example.com', '52998224725', '529.982.247-25', '98888', 'Souza', 'Lima', 'Praça da Sé', 'Praça', '01001', 'Apto 12', '"Sé"', 'customer_email', 'customer_cpf', 'shipping_address', 'unit_price_cents', 'total_cents'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "A resposta contém '{$forbidden}'.");
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'nome completo' => ['Fernanda Souza Lima', 'Fernanda'],
            'um nome' => ['Fernanda', 'Fernanda'],
            'espaços nas pontas' => ['  Ana Maria ', 'Ana'],
            'espaço duplo no meio' => ['Ana  Maria', 'Ana'],
        ];
    }

    #[DataProvider('names')]
    public function test_mascaramento_do_nome(string $name, string $expected): void
    {
        $order = Order::factory()->create(['customer_name' => $name]);

        $this->trackByToken($order)->assertJsonPath('customer_first_name', $expected);
    }

    public function test_item_sem_tamanho_e_material_usa_textos_padrao(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'size_name_snapshot' => null, 'material_name_snapshot' => null]);

        $this->trackByToken($order)
            ->assertJsonPath('items.0.size', 'Padrão (12cm)')
            ->assertJsonPath('items.0.material', 'PLA Premium');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function statuses(): array
    {
        return [
            'pending_payment' => ['pending_payment', 'Aguardando Pagamento', 'warning', 'heroicon-m-clock'],
            'in_queue' => ['in_queue', 'Na Fila de Impressão 3D', 'gray', 'heroicon-m-queue-list'],
            'printing' => ['printing', 'Imprimindo na Fazenda 3D', 'info', 'heroicon-m-cpu-chip'],
            'finishing_and_packaging' => ['finishing_and_packaging', 'Acabamento & Embalagem', 'primary', 'heroicon-m-gift'],
            'shipped' => ['shipped', 'Expedido / Em Trânsito', 'success', 'heroicon-m-truck'],
            'delivered' => ['delivered', 'Entregue com Sucesso', 'success', 'heroicon-m-check-circle'],
            'cancelled' => ['cancelled', 'Cancelado', 'danger', 'heroicon-m-x-circle'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_label_cor_e_icone_de_cada_status(string $status, string $label, string $color, string $icon): void
    {
        $order = Order::factory()->status(OrderStatus::from($status))->create();
        ProductionLog::factory()->create(['order_id' => $order->id, 'previous_status' => 'x', 'new_status' => $status]);

        $this->trackByToken($order)
            ->assertJsonPath('status_label', $label)
            ->assertJsonPath('status_color', $color)
            ->assertJsonPath('status_icon', $icon)
            ->assertJsonPath('timeline.0.label', $label)
            ->assertJsonPath('timeline.0.color', $color);
    }

    public function test_timeline_em_ordem_cronologica_crescente_com_datas_formatadas(): void
    {
        $order = Order::factory()->create();
        // Inseridos fora de ordem, com horários distintos (tempo congelado não gera empate aqui).
        ProductionLog::factory()->create(['order_id' => $order->id, 'previous_status' => 'in_queue', 'new_status' => 'printing', 'notes' => 'c', 'created_at' => Carbon::parse('2026-09-23 11:00:00')]);
        ProductionLog::factory()->create(['order_id' => $order->id, 'previous_status' => 'checkout', 'new_status' => 'pending_payment', 'notes' => 'a', 'created_at' => Carbon::parse('2026-09-23 09:00:00')]);
        ProductionLog::factory()->create(['order_id' => $order->id, 'previous_status' => 'pending_payment', 'new_status' => 'in_queue', 'notes' => 'b', 'created_at' => Carbon::parse('2026-09-23 10:15:00')]);

        $timeline = $this->trackByToken($order)->json('timeline');

        $this->assertSame(['a', 'b', 'c'], array_column($timeline, 'description'));
        $this->assertSame(['pending_payment', 'in_queue', 'printing'], array_column($timeline, 'status'));
        $this->assertSame(['23/09/2026 09:00', '23/09/2026 10:15', '23/09/2026 11:00'], array_column($timeline, 'timestamp'));
        $this->assertSame('2026-09-23T09:00:00-03:00', $timeline[0]['iso_date']);
    }

    public function test_status_desconhecido_na_timeline_usa_ucfirst_e_cinza(): void
    {
        $order = Order::factory()->create();
        ProductionLog::factory()->create(['order_id' => $order->id, 'new_status' => 'refunded']);

        $this->trackByToken($order)
            ->assertJsonPath('timeline.0.label', 'Refunded')
            ->assertJsonPath('timeline.0.color', 'gray');
    }

    public function test_prefixo_de_autor_e_removido_das_notas(): void
    {
        $order = Order::factory()->create();
        ProductionLog::factory()->create(['order_id' => $order->id, 'notes' => '[João Operador] Extrusão iniciada.']);

        $this->trackByToken($order)->assertJsonPath('timeline.0.description', 'Extrusão iniciada.');
    }

    public function test_timeline_expoe_id_de_pagamento_do_gateway(): void
    {
        // BUG CONHECIDO: notas internas (ID de pagamento, erro do gateway) aparecem na timeline pública — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(987654321, 'approved'));
        $token = $this->postJson('/api/checkout', $payload)->assertStatus(201)->json('tracking_token');

        $descriptions = array_column($this->postJson('/api/orders/track', ['tracking_token' => $token])->json('timeline'), 'description');

        $this->assertContains('Pagamento autorizado instantaneamente via cartão (ID: 987654321)', $descriptions);
        $this->assertContains('Pedido persistido com snapshots e valores auditados pelo backend.', $descriptions);
    }

    public function test_timeline_expoe_mensagem_de_erro_do_gateway(): void
    {
        // BUG CONHECIDO: notas internas (ID de pagamento, erro do gateway) aparecem na timeline pública — ver teste gêmeo em KnownBugs
        ['payload' => $payload] = $this->simpleCheckout('credit_card');
        $this->mpHttp->pushException(new \RuntimeException('cURL error 28: Operation timed out'));
        $orderNumber = $this->postJson('/api/checkout', $payload)->json('order_number');
        $token = Order::where('order_number', $orderNumber)->value('tracking_token');

        $descriptions = array_column($this->postJson('/api/orders/track', ['tracking_token' => $token])->json('timeline'), 'description');

        $this->assertContains('Cobrança não concluída no gateway: Falha na autorização do cartão de crédito: cURL error 28: Operation timed out', $descriptions);
    }
}
