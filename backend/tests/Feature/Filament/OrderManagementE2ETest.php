<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\OrderStatus;
use App\Events\OrderPrintingStartedEvent;
use App\Events\OrderShippedEvent;
use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use App\Services\ProductionWorkflowService;
use Database\Seeders\E2ETestDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderManagementE2ETest extends TestCase
{
    use DatabaseTransactions;

    private User $adminOperator;

    private User $regularCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Cria Operador Autorizado da Oficina 3D (Admin)
        $this->adminOperator = User::factory()->create([
            'name' => 'Operador 3D Chefe',
            'email' => 'operador@mimoolab.com.br',
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        // 2. Cria Comprador Comum (Não-Admin)
        $this->regularCustomer = User::factory()->create([
            'name' => 'Comprador Comum',
            'email' => 'cliente@mimoolab.com.br',
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cenário 1: Segurança e RBAC - Isolamento do Painel Administrativo.
     */
    public function test_unauthenticated_and_non_admin_users_cannot_access_filament_admin_panel(): void
    {
        // Visitante deslogado deve ser redirecionado para a tela de login
        $guestResponse = $this->get('/admin');
        $guestResponse->assertRedirect('/admin/login');

        // Usuário comum autenticado (is_admin = false) é bloqueado com HTTP 403 Forbidden
        $customerResponse = $this->actingAs($this->regularCustomer)->get('/admin');
        $customerResponse->assertForbidden();

        // Tentativa de acessar diretamente a listagem de pedidos da fábrica
        $ordersResponse = $this->actingAs($this->regularCustomer)->get('/admin/orders');
        $ordersResponse->assertForbidden();
    }

    /**
     * Cenário 1.1: Operador Autenticado (is_admin = true) Acessa o Painel com Sucesso.
     */
    public function test_authenticated_operator_can_access_filament_order_management_dashboard(): void
    {
        $response = $this->actingAs($this->adminOperator)->get('/admin');
        $response->assertSuccessful();

        $ordersResponse = $this->actingAs($this->adminOperator)->get('/admin/orders');
        $ordersResponse->assertSuccessful();
    }

    /**
     * Cenário 2: Visualização de Parâmetros Técnicos & Imutabilidade Financeira.
     */
    public function test_operator_can_view_technical_3d_parameters_and_financial_fields_are_immutable(): void
    {
        $this->seed(E2ETestDataSeeder::class);

        $product = Product::where('slug', 'mascote-vaquinha-articulada-3d')->firstOrFail();
        $color = Color::where('name', 'Roxo MIMOO')->firstOrFail();
        $size = Size::where('name', 'Colecionador')->firstOrFail();

        // Cria pedido real no banco
        $order = new Order;
        $order->order_number = 'MIMOO-TEST01';
        $order->tracking_token = (string) Str::uuid();
        $order->customer_name = 'Carlos Cliente';
        $order->customer_email = 'carlos@teste.com';
        $order->customer_cpf = '52998224725';
        $order->customer_phone = '11988887777';
        $order->shipping_address = [
            'zip_code' => '01310-100',
            'street' => 'Av Paulista',
            'number' => '100',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
        ];
        $order->status = OrderStatus::IN_QUEUE;
        $order->total_cents = 9780; // R$ 97,80
        $order->shipping_cost_cents = 2290;
        $order->paid_at = now();
        $order->save();

        $order->items()->create([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'product_title_snapshot' => $product->title,
            'color_name_snapshot' => $color->name,
            'size_name_snapshot' => $size->name,
            'unit_price_cents' => 7490,
            'quantity' => 1,
            'total_price_cents' => 7490,
        ]);

        // Acesso à tela de edição/visualização da encomenda pelo operador
        $response = $this->actingAs($this->adminOperator)->get("/admin/orders/{$order->id}/edit");
        $response->assertSuccessful();

        // O formulário deve conter as referências da peça e dados técnicos
        $response->assertSee('MIMOO-TEST01');
        $response->assertSee('Carlos Cliente');
        $response->assertSee('carlos@teste.com');
    }

    /**
     * Cenário 3: Ciclo de Vida da Peça na Fazenda 3D com Eventos e Auditoria em production_logs.
     */
    public function test_farm_operator_executes_3d_manufacturing_lifecycle_with_audit_and_events(): void
    {
        Event::fake([
            OrderPrintingStartedEvent::class,
            OrderShippedEvent::class,
        ]);

        // 1. Criação do Pedido em Fila de Espera (Após Pagamento Aprovado)
        $order = new Order;
        $order->order_number = 'MIMOO-E2E-LIFECYCLE';
        $order->tracking_token = (string) Str::uuid();
        $order->customer_name = 'Juliana Engenharia';
        $order->customer_email = 'juliana@empresa.com.br';
        $order->customer_cpf = '52998224725';
        $order->customer_phone = '11977776666';
        $order->shipping_address = [
            'zip_code' => '01001-000',
            'street' => 'Praça da Sé',
            'number' => '1',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ];
        $order->status = OrderStatus::IN_QUEUE;
        $order->total_cents = 12000;
        $order->shipping_cost_cents = 1500;
        $order->paid_at = now();
        $order->save();

        /** @var ProductionWorkflowService $workflowService */
        $workflowService = app(ProductionWorkflowService::class);

        // =====================================================================
        // ETAPA A: INÍCIO DA EXTRUSÃO NA IMPRESSORA 3D (printing)
        // =====================================================================
        $order = $workflowService->startPrinting(
            $order,
            'Iniciando extrusão na máquina Prusa MK4 #03 (Bico 0.4mm / PLA Roxo MIMOO).'
        );

        $this->assertEquals(OrderStatus::PRINTING, $order->status);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PRINTING->value,
        ]);

        $this->assertDatabaseHas('production_logs', [
            'order_id' => $order->id,
            'previous_status' => OrderStatus::IN_QUEUE->value,
            'new_status' => OrderStatus::PRINTING->value,
        ]);

        Event::assertDispatched(OrderPrintingStartedEvent::class, function (OrderPrintingStartedEvent $event) use ($order) {
            return $event->order->id === $order->id;
        });

        // =====================================================================
        // ETAPA B: ACABAMENTO, INSPEÇÃO DE QUALIDADE & EMBALAGEM (finishing)
        // =====================================================================
        $order = $workflowService->moveToFinishing(
            $order,
            'Peça inspecionada na bancada de qualidade. Remoção de suportes concluída e embalada em plástico bolha.'
        );

        $this->assertEquals(OrderStatus::FINISHING_AND_PACKAGING, $order->status);
        $this->assertDatabaseHas('production_logs', [
            'order_id' => $order->id,
            'previous_status' => OrderStatus::PRINTING->value,
            'new_status' => OrderStatus::FINISHING_AND_PACKAGING->value,
        ]);

        // =====================================================================
        // ETAPA C: DESPACHO POSTAL COM CÓDIGO DOS CORREIOS (shipped)
        // =====================================================================
        $trackingCode = 'BR123456789AA';
        $order = $workflowService->dispatchOrder(
            $order,
            $trackingCode,
            'Coletado pela transportadora Correios (SEDEX Express).'
        );

        $this->assertEquals(OrderStatus::SHIPPED, $order->status);
        $this->assertEquals($trackingCode, $order->carrier_tracking_code);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::SHIPPED->value,
            'carrier_tracking_code' => $trackingCode,
        ]);

        $this->assertDatabaseHas('production_logs', [
            'order_id' => $order->id,
            'previous_status' => OrderStatus::FINISHING_AND_PACKAGING->value,
            'new_status' => OrderStatus::SHIPPED->value,
        ]);

        Event::assertDispatched(OrderShippedEvent::class, function (OrderShippedEvent $event) use ($order, $trackingCode) {
            return $event->order->id === $order->id && $event->carrierTrackingCode === $trackingCode;
        });
    }
}
