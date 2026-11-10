<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Logging;

use App\Events\OrderPrintingStartedEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Size;
use App\Models\User;
use App\Support\ErrorReference;
use ErrorException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ViewException;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use TypeError;

/**
 * M-54 (bug #31): erro de banco DENTRO de um template Blade vira Illuminate\View\ViewException, que herda de
 * ErrorException. Antes, o ErrorReference gravava a mensagem dela em message_safe: o SQL da QueryException de
 * dentro, com os valores SEM aspas (nome, termo de busca), host e nome do banco, no laravel.log e no
 * failed_jobs.exception. Agora message_safe só sai se a cadeia inteira (getPrevious) for de erros do PHP, sem
 * erro de banco, e a mensagem não tiver marca de SQL; SQLSTATE e código do driver vêm da cadeia inteira.
 *
 * Com as dependências de dev, o Ignition troca a ViewException do Laravel pela do Spatie (guarda só a raiz);
 * em produção (--no-dev) chega a cadeia aninhada do Laravel: os testes de unidade montam essa cadeia à mão.
 *
 * Os testes leem o ARQUIVO de log de verdade (canal single) e a linha real do failed_jobs.
 */
#[Group('characterization')]
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

        // SQLSTATE e código do driver nos dois destinos (o template aninhado embrulha duas vezes).
        $stored = json_decode($failed->exception, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('HY000', $stored['sqlstate']);
        $this->assertSame(1, $stored['driver_code']);
        $this->assertArrayNotHasKey('message_safe', $stored);
        $this->assertStringContainsString('"sqlstate":"HY000","driver_code":1', $log);
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

    public function test_cadeia_de_producao_view_view_query_sai_sem_mensagem_e_com_o_sqlstate(): void
    {
        $context = ErrorReference::context($this->productionViewChain(), 'ABCDEFGH');

        $this->assertSame(ViewException::class, $context['exception']);
        $this->assertSame('HY000', $context['sqlstate']);
        $this->assertSame(2002, $context['driver_code']);
        $this->assertArrayNotHasKey('message_safe', $context);
        $this->assertSame(
            ['error_ref', 'exception', 'sqlstate', 'driver_code', 'location', 'frames'],
            array_keys($context)
        );
        $this->assertNoSql((string) json_encode($context));
    }

    public function test_view_com_falha_que_nao_e_erro_do_php_sai_sem_mensagem(): void
    {
        $smtp = new TransportException('Expected response code 250 but got 550 for '.self::EMAIL);
        $view = new ViewException($smtp->getMessage().' (View: '.resource_path('views/emails/orders/shipped.blade.php').')', 0, 1, __FILE__, __LINE__, $smtp);

        $context = ErrorReference::context($view);

        $this->assertArrayNotHasKey('message_safe', $context);
        $this->assertNull($context['sqlstate']);
    }

    public function test_view_com_erro_do_php_mantem_a_mensagem_com_caminho_relativo(): void
    {
        $inner = new ErrorException('Undefined variable $pedido');
        $view = new ViewException($inner->getMessage().' (View: '.resource_path('views/emails/orders/shipped.blade.php').')', 0, 1, __FILE__, __LINE__, $inner);

        $this->assertSame(
            'Undefined variable $pedido (View: resources/views/emails/orders/shipped.blade.php)',
            ErrorReference::context($view)['message_safe']
        );
    }

    public function test_erro_do_php_com_marca_de_sql_na_mensagem_sai_sem_mensagem(): void
    {
        $this->assertArrayNotHasKey('message_safe', ErrorReference::context(new ErrorException('SQLSTATE[HY000] select '.self::NAME)));
        $this->assertArrayNotHasKey('message_safe', ErrorReference::context(new ErrorException('falhou (SQL: select '.self::NAME.')')));
    }

    public function test_type_error_com_caminho_absoluto_vira_relativo(): void
    {
        $error = new TypeError('App\Support\Cents::fromReais(): Argument #1 must be of type int, array given, called in '
            .app_path('Services/OrderCreationService.php').' on line 120');

        $message = ErrorReference::context($error)['message_safe'];

        $this->assertSame(
            'App\Support\Cents::fromReais(): Argument #1 must be of type int, array given, called in app/Services/OrderCreationService.php on line 120',
            $message
        );
        $this->assertStringNotContainsString(base_path(), $message);
    }

    public function test_cadeia_circular_nao_trava(): void
    {
        $a = new ErrorException('a');
        $b = new ErrorException('b', 0, 1, __FILE__, __LINE__, $a);
        (new ReflectionProperty(\Exception::class, 'previous'))->setValue($a, $b);

        $this->assertSame('b', ErrorReference::context($b)['message_safe']);
    }

    /**
     * 3.1: o que passava pelo higienizador e agora vira [x].
     */
    public function test_sanitizador_cobre_token_cpf_com_espaco_email_sem_ponto_e_usuario_de_url(): void
    {
        $this->assertSame(
            'token [x] uuid [x] cpf [x] tel (11) [x] email [x] url https://[x]@db.interno/x linha 42',
            ErrorReference::sanitizeMessage(
                'token tk_live9f8e7d6c5b4a3f2e1d0c uuid a331cd47-b8e2-4b72-ba10-cede00112233 cpf 529 982 247 25 '
                .'tel (11) 98765 4321 email fulana@localhost url https://admin:s3nh4@db.interno/x linha 42'
            )
        );

        // O texto técnico continua legível.
        $this->assertSame(
            'App\Services\X::y(): Argument #1 ($cpf) must be of type int, string given',
            ErrorReference::sanitizeMessage('App\Services\X::y(): Argument #1 ($cpf) must be of type int, string given')
        );
    }

    /**
     * Como chega em produção (sem o Ignition): template aninhado (item-row dentro do e-mail) embrulha a
     * QueryException duas vezes.
     */
    private function productionViewChain(): ViewException
    {
        $pdo = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $pdo->errorInfo = ['HY000', 2002, 'Connection refused'];
        $query = new QueryException('mariadb', 'select * from orders where customer_name like ?', ['%'.self::NAME.'%'], $pdo);
        $inner = new ViewException($query->getMessage().' (View: '.resource_path('views/emails/components/item-row.blade.php').')', 0, 1, __FILE__, __LINE__, $query);

        return new ViewException($inner->getMessage().' (View: '.resource_path('views/emails/orders/printing.blade.php').')', 0, 1, __FILE__, __LINE__, $inner);
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
