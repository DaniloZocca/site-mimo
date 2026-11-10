<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\ProductionWorkflowService;
use App\Support\DatabaseSettings;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concurrency\Support\ConcurrencyTestCase;

/**
 * M-45: as três diferenças do MariaDB que falham sem dar erro (isolamento, linhas afetadas, collation) e a espera
 * por trava, provadas com duas conexões de verdade (a do teste e uma segunda conexão PDO ao mesmo banco).
 */
#[Group('concurrency')]
class MariaDbSettingsTest extends ConcurrencyTestCase
{
    public function test_cada_sessao_abre_em_read_committed_com_espera_de_10_s_e_fuso_utc(): void
    {
        foreach ([DB::connection(), $this->secondConnection()] as $connection) {
            $this->assertSame(DatabaseSettings::ISOLATION, $connection->selectOne('SELECT @@transaction_isolation AS v')->v);
            $this->assertSame(DatabaseSettings::LOCK_WAIT_TIMEOUT_SECONDS, (int) $connection->selectOne('SELECT @@innodb_lock_wait_timeout AS v')->v);
            $this->assertSame('+00:00', $connection->selectOne('SELECT @@session.time_zone AS v')->v);
        }
    }

    public function test_releitura_dentro_da_transacao_ve_o_que_a_outra_conexao_commitou(): void
    {
        $order = Order::factory()->create();

        DB::beginTransaction();
        $this->assertSame('pending_payment', DB::table('orders')->where('id', $order->id)->value('status'));

        $this->secondConnection()->table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);

        // Em REPEATABLE READ (padrão do MariaDB) esta leitura ainda devolveria pending_payment
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order->id)->value('status'));
        DB::commit();
    }

    public function test_travar_linha_que_a_outra_conexao_mudou_depois_da_primeira_leitura_nao_da_erro(): void
    {
        $order = Order::factory()->create();

        DB::beginTransaction();
        DB::table('orders')->where('id', $order->id)->value('status');

        $this->secondConnection()->table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);

        // Em REPEATABLE READ o MariaDB 11.8 (innodb_snapshot_isolation) recusaria com o erro 1020
        // "Record has changed since last read"; em READ COMMITTED a trava pega o valor atual
        $locked = DB::table('orders')->where('id', $order->id)->lockForUpdate()->value('status');
        DB::commit();

        $this->assertSame('cancelled', $locked);
    }

    public function test_update_que_regrava_o_mesmo_valor_conta_a_linha(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::IN_QUEUE]);

        // Sem FOUND_ROWS o MariaDB devolveria 0 ("nenhuma linha alterada")
        $this->assertSame(1, Order::whereKey($order->id)->where('status', 'in_queue')->update(['status' => 'in_queue']));
    }

    public function test_transicao_com_extra_igual_ao_do_banco_grava_normalmente(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::FINISHING_AND_PACKAGING, 'carrier_tracking_code' => 'BR123']);

        $applied = app(ProductionWorkflowService::class)->transitionFrom(
            $order,
            OrderStatus::FINISHING_AND_PACKAGING,
            OrderStatus::SHIPPED,
            'Expedido',
            extra: ['carrier_tracking_code' => 'BR123'],
        );

        $this->assertTrue($applied);
        $this->assertSame(OrderStatus::SHIPPED, $order->refresh()->status);
    }

    public function test_colunas_de_token_e_email_comparam_exato(): void
    {
        foreach (DatabaseSettings::BINARY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $collation = DB::selectOne(
                    'SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                )?->c;
                $this->assertSame(DatabaseSettings::BINARY_COLLATION, $collation, "{$table}.{$column}");
            }
        }

        $order = Order::factory()->create(['order_number' => 'MIMOO-ABCD1234', 'customer_email' => 'Ana@Exemplo.com']);
        $this->assertSame(0, Order::where('order_number', 'mimoo-abcd1234')->count());
        $this->assertSame(0, Order::where('customer_email', 'ana@exemplo.com')->count());
        $this->assertSame(1, Order::where('order_number', 'MIMOO-ABCD1234')->count());

        DB::table('sessions')->insert(['id' => 'SessaoAbC123', 'payload' => '', 'last_activity' => time()]);
        $this->assertSame(0, DB::table('sessions')->where('id', 'sessaoabc123')->count());

        // Como no Postgres: a coluna aceita e-mails que diferem só em maiúscula (o model normaliza para minúscula)
        DB::table('customers')->insert(['id' => (string) str()->uuid(), 'name' => 'A', 'email' => 'Ana@x.com', 'password' => 'x']);
        DB::table('customers')->insert(['id' => (string) str()->uuid(), 'name' => 'B', 'email' => 'ana@x.com', 'password' => 'x']);
        $this->assertSame(2, DB::table('customers')->whereIn('email', ['Ana@x.com', 'ana@x.com'])->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customers')->insert(['id' => (string) str()->uuid(), 'name' => 'C', 'email' => 'ana@x.com', 'password' => 'x']);
    }

    public function test_meus_pedidos_acha_pedido_com_email_em_maiuscula_e_espacos(): void
    {
        $customer = Customer::factory()->create(['email' => 'cliente@exemplo.com', 'email_verified_at' => now()]);
        $order = Order::factory()->create(['customer_email' => '  CLIENTE@Exemplo.com ']);

        $this->actingAs($customer, 'customer')->getJson('/api/conta/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number);
    }

    public function test_busca_do_painel_pelo_numero_do_pedido_continua_sem_diferenca_de_maiuscula(): void
    {
        $order = Order::factory()->create(['order_number' => 'MIMOO-BUSCA123']);
        $other = Order::factory()->create(['order_number' => 'MIMOO-OUTRO999']);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListOrders::class)
            ->searchTable('mimoo-busca')
            ->assertCanSeeTableRecords([$order])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * Segunda conexão PDO ao mesmo banco, com a mesma configuração (conexão `mariadb`).
     */
    private function secondConnection(): Connection
    {
        config(['database.connections.mariadb_segunda' => config('database.connections.'.config('database.default'))]);

        return DB::connection('mariadb_segunda');
    }
}
