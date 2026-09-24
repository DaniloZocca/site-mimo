<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionLog;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Valida a própria rede de segurança: banco isolado, rede bloqueada, factories funcionando.
 */
#[Group('characterization')]
class HarnessSmokeTest extends CharacterizationTestCase
{
    public function test_conexao_ativa_e_sqlite_em_memoria(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_trava_reprova_quando_banco_nao_e_memoria(): void
    {
        config(['database.connections.sqlite.database' => database_path('nao-usar.sqlite')]);

        try {
            $this->beforeRefreshingDatabase();
            $this->fail('A trava deveria ter reprovado o teste.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('TRAVA DE SEGURANÇA', $e->getMessage());
        } finally {
            config(['database.connections.sqlite.database' => ':memory:']);
        }

        $this->assertFileDoesNotExist(database_path('nao-usar.sqlite'));
    }

    public function test_http_facade_bloqueia_requisicoes_nao_programadas(): void
    {
        $this->expectException(RuntimeException::class);

        Http::get('https://api.mercadopago.com/v1/payments/1');
    }

    public function test_sdk_mercado_pago_usa_cliente_falso_e_bloqueia_requisicao_nao_programada(): void
    {
        $service = app(MercadoPagoService::class);

        // getPayment engole exceções e devolve null: a requisição chegou ao cliente falso, não à rede.
        $this->assertNull($service->getPayment(123456));
        $this->assertCount(1, $this->mpHttp->requests);
        $this->assertSame('/v1/payments/123456', $this->mpHttp->requests[0]->getUri());
    }

    public function test_factories_criam_grafo_de_pedido(): void
    {
        $item = OrderItem::factory()->create();
        ProductionLog::factory()->create(['order_id' => $item->order_id]);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, $item->order->items()->count());
        $this->assertSame(1, $item->order->productionLogs()->count());
    }

    public function test_tempo_esta_congelado(): void
    {
        $this->assertSame(self::FROZEN_NOW, now()->format('Y-m-d H:i:s'));
    }
}
