<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs;

use App\Events\OrderPrintingStartedEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Size;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-54 (bug #31): erro de banco DENTRO de um template Blade vira Illuminate\View\ViewException, que herda de
 * ErrorException. O ErrorReference trata ErrorException como "erro do PHP" e grava a mensagem higienizada em
 * message_safe; só que a mensagem da ViewException é a da QueryException de dentro: o SQL com os valores
 * substituídos SEM aspas (QueryException::formatMessage), host, porta e nome do banco. O higienizador só tira
 * texto entre aspas, e-mail e números: nome, endereço e termo de busca passam. Vai para o laravel.log (handler
 * do bootstrap/app.php) e para failed_jobs.exception (PrivacySafeFailedJobProvider).
 *
 * Os testes leem o ARQUIVO de log de verdade (canal single) e a linha real do failed_jobs.
 */
#[Group('known-bugs')]
class ViewExceptionLogPrivacyTest extends CharacterizationTestCase
{
    private const NAME = 'Fulana Secreta Souza';

    private const EMAIL = 'fulana.segredo@exemplo.com';

    protected bool $fakeQueue = false;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'm54-log-');
        config([
            'app.debug' => false,
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    /**
     * Caminho real do e-mail: "Iniciar impressão" → OrderPrintingStartedEvent → listener na fila
     * (SendOrderPrintingNotification, 3 tentativas) → Mail::sendNow → template emails.orders.printing →
     * componente item-row lê $item->size (relação, quando o snapshot do tamanho está vazio) → consulta ao banco
     * falha → ViewException. O worker reporta cada tentativa (handler do bootstrap/app.php → laravel.log) e,
     * na terceira, grava failed_jobs (PrivacySafeFailedJobProvider).
     */
    public function test_email_da_fila_com_erro_de_banco_no_template_nao_grava_sql_no_log_nem_no_failed_jobs(): void
    {
        // E-mail de verdade (transporte array, renderiza o template); a base troca o Mail por um fake.
        config(['mail.default' => 'array', 'queue.default' => 'database', 'queue.failed.database' => 'sqlite']);
        Mail::swap(Mail::getFacadeRoot()->manager);
        $this->app->forgetInstance('queue.failer');

        $order = Order::factory()->create(['customer_name' => self::NAME, 'customer_email' => self::EMAIL]);
        // size_name_snapshot é nullable: sem ele o item-row cai em $item->size, uma consulta DENTRO do template.
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'size_id' => Size::factory()->create()->id,
            'size_name_snapshot' => null,
        ]);

        // Erro de banco durante a renderização (no servidor: conexão perdida, lock wait timeout, tabela travada).
        Schema::rename('sizes', 'sizes_m54');

        event(new OrderPrintingStartedEvent($order->fresh()));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
            $this->travel(10)->minutes();
        }

        $failed = DB::table('failed_jobs')->sole();
        $log = (string) file_get_contents($this->logPath);

        $this->assertStringContainsString('Erro não tratado.', $log);
        $this->assertStringContainsString('ViewException', $log);
        $this->assertStringContainsString('ViewException', $failed->exception);

        $this->assertNoSql($failed->exception);
        $this->assertNoSql($log);
    }

    /**
     * Mesmo defeito com dado pessoal no SQL: a tabela de pedidos do painel busca DENTRO do template
     * (filament/tables index.blade.php chama $getRecords()). Uma falha do banco nessa consulta leva o termo
     * buscado (nome da cliente) para o log, sem aspas, e o higienizador não o reconhece.
     */
    public function test_busca_do_painel_com_erro_de_banco_nao_grava_o_termo_buscado_no_log(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Order::factory()->create(['customer_name' => self::NAME, 'customer_email' => self::EMAIL]);

        // Erro de banco na consulta da tabela (no servidor: conexão perdida, lock wait timeout).
        Schema::rename('production_logs', 'production_logs_m54');

        $this->get('/admin/orders?tableSearch='.rawurlencode(self::NAME))->assertStatus(500);

        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('Erro não tratado.', $log);
        $this->assertStringContainsString('ViewException', $log);
        $this->assertNoSql($log);
        $this->assertStringNotContainsStringIgnoringCase('Secreta', $log);
    }

    private function assertNoSql(string $text): void
    {
        $this->assertStringNotContainsString(self::NAME, $text);
        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertStringNotContainsStringIgnoringCase('select ', $text);
        $this->assertStringNotContainsString('SQL:', $text);
        $this->assertStringNotContainsString('Database:', $text);
    }
}
