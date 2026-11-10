<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Logging;

use App\Support\Cents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use TypeError;

/**
 * M-47: erro não tratado (rota, fila, comando) vai para o laravel.log só com o contexto do ErrorReference.
 * Os testes leem o ARQUIVO de log de verdade (canal single), para pegar também o que o formatador do Monolog
 * escreveria (mensagem, "[stacktrace]", "#0 ...").
 */
#[Group('characterization')]
class UnhandledExceptionLogPrivacyTest extends CharacterizationTestCase
{
    private const EMAIL = 'fulana.segredo@exemplo.com';

    private const CPF = '52998224725';

    protected bool $fakeQueue = false;

    private string $logPath;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'm47-log-');
        config([
            'app.debug' => false,
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        ini_restore('zend.exception_ignore_args');

        parent::tearDown();
    }

    public function test_query_exception_nao_tratada_loga_so_o_contexto_seguro_e_o_500_leva_o_codigo(): void
    {
        Route::get('/api/m47/banco', fn () => throw $this->queryException());

        $response = $this->getJson('/api/m47/banco');

        $entry = $this->singleUnhandledEntry();
        $context = $entry['context'];
        $this->assertSame('error', $entry['level']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', (string) $context['error_ref']);
        $this->assertSame(QueryException::class, $context['exception']);
        $this->assertSame('23000', $context['sqlstate']);
        $this->assertSame(1062, $context['driver_code']);
        $this->assertSame('GET api/m47/banco', $context['route']);
        $this->assertArrayNotHasKey('message_safe', $context);
        $this->assertSame(
            ['error_ref', 'exception', 'sqlstate', 'driver_code', 'location', 'frames', 'route'],
            array_keys($context)
        );

        $response->assertStatus(500);
        $this->assertSame(['message' => 'Server Error', 'error_ref' => $context['error_ref']], $response->json());

        $this->assertNoSensitiveData($response->getContent());
        $this->assertLogFileIsClean();
    }

    public function test_type_error_tem_message_safe_higienizada_e_frames_sem_argumentos(): void
    {
        // Liga os argumentos no trace (em produção vêm desligados) para provar que os frames não os leem.
        ini_set('zend.exception_ignore_args', '0');

        Route::get('/api/m47/tipo', function () {
            // TypeError de verdade, lançado em app/Support/Cents.php, com o e-mail e o CPF nos argumentos.
            return Cents::fromReais([self::EMAIL, self::CPF]); // @phpstan-ignore argument.type
        });
        Route::get('/api/m47/tipo-com-dados', function () {
            throw new TypeError('App\Services\X::y(): Argument #1 ($cpf) must be of type int, string given, '
                ."valor '".self::EMAIL."' cpf ".self::CPF.' em https://loja.test/rastreio?token=abc123&cpf='.self::CPF);
        });

        $this->getJson('/api/m47/tipo')->assertStatus(500);
        $this->getJson('/api/m47/tipo-com-dados')->assertStatus(500);

        $entries = $this->unhandledEntries();
        $this->assertCount(2, $entries);

        // Frames: Classe::método + arquivo:linha em app/, sem argumentos.
        $real = $entries[0]['context'];
        $this->assertSame(TypeError::class, $real['exception']);
        $this->assertNotEmpty($real['frames']);
        $this->assertLessThanOrEqual(5, count($real['frames']));
        $this->assertMatchesRegularExpression('#^App\\\\Support\\\\Cents::fromReais app/Support/Cents\.php:\d+$#', $real['frames'][0]);
        $this->assertStringContainsString('must be of type string|int|float|null, array given', $real['message_safe']);

        // Mensagem com dados: os valores somem, o texto técnico fica.
        $this->assertSame(
            'App\Services\X::y(): Argument #1 ($cpf) must be of type int, string given, valor [x] cpf [x] em https://loja.test/rastreio?[x]',
            $entries[1]['context']['message_safe']
        );

        $this->assertLogFileIsClean();
    }

    public function test_erro_com_query_exception_dentro_continua_sem_mensagem_e_com_o_sqlstate_da_interna(): void
    {
        Route::get('/api/m47/embrulhado', fn () => throw new RuntimeException(
            'Falhou ao gravar '.self::EMAIL, 0, $this->queryException()
        ));

        $this->getJson('/api/m47/embrulhado')->assertStatus(500);

        $context = $this->singleUnhandledEntry()['context'];
        $this->assertSame(RuntimeException::class, $context['exception']);
        $this->assertSame('23000', $context['sqlstate']);
        $this->assertArrayNotHasKey('message_safe', $context);
        $this->assertLogFileIsClean();
    }

    public function test_job_da_fila_que_falha_loga_e_grava_failed_jobs_sem_dados_pessoais(): void
    {
        // O failed_jobs segue o DB_CONNECTION do ambiente (pgsql/mariadb no CI): aqui fica no SQLite do teste.
        config(['queue.default' => 'database', 'queue.failed.database' => 'sqlite']);
        $this->app->forgetInstance('queue.failer');
        M47FailingJob::dispatch();

        Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--stop-when-empty' => true]);

        $entries = $this->unhandledEntries();
        $this->assertNotEmpty($entries);
        $this->assertSame('console', $entries[0]['context']['route']);
        $this->assertSame(RuntimeException::class, $entries[0]['context']['exception']);

        $failed = DB::table('failed_jobs')->sole();
        $stored = json_decode($failed->exception, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(RuntimeException::class, $stored['exception']);
        $this->assertSame($entries[0]['context']['error_ref'], $stored['error_ref']);
        $this->assertNoSensitiveData($failed->exception);
        $this->assertSame(M47FailingJob::class, json_decode($failed->payload, true)['displayName']);

        $this->assertLogFileIsClean();
    }

    public function test_comando_artisan_que_falha_loga_sem_dados_pessoais(): void
    {
        // O closure do comando é religado ao próprio comando: o e-mail vai numa variável, não em self::.
        $email = self::EMAIL;
        Artisan::command('m47:falha', fn () => throw new RuntimeException('SMTP recusou '.$email));

        $status = $this->app->make(ConsoleKernel::class)->handle(new ArrayInput(['command' => 'm47:falha']), new BufferedOutput);

        $this->assertSame(1, $status);
        $context = $this->singleUnhandledEntry()['context'];
        $this->assertSame('console', $context['route']);
        $this->assertSame(RuntimeException::class, $context['exception']);
        $this->assertLogFileIsClean();
    }

    public function test_validacao_e_404_continuam_sem_log(): void
    {
        Route::post('/api/m47/validacao', fn () => throw ValidationException::withMessages(['email' => self::EMAIL]));

        $this->postJson('/api/m47/validacao')->assertStatus(422);
        $this->getJson('/api/m47/nao-existe')->assertStatus(404);

        $this->assertSame([], $this->logged);
        $this->assertSame('', (string) file_get_contents($this->logPath));
    }

    private function queryException(): QueryException
    {
        $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '".self::EMAIL."' for key 'x'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry '".self::EMAIL."' for key 'x'"];

        return new QueryException(
            'mariadb',
            'insert into orders (customer_email, customer_cpf) values (?, ?)',
            [self::EMAIL, self::CPF],
            $pdo
        );
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function unhandledEntries(): array
    {
        return array_values(array_filter($this->logged, fn (array $e): bool => $e['message'] === 'Erro não tratado.'));
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function singleUnhandledEntry(): array
    {
        $entries = $this->unhandledEntries();
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    private function assertLogFileIsClean(): void
    {
        $contents = (string) file_get_contents($this->logPath);

        $this->assertStringContainsString('Erro não tratado.', $contents);
        $this->assertNoSensitiveData($contents);
        $this->assertStringNotContainsString('[stacktrace]', $contents);
        $this->assertStringNotContainsString('#0 ', $contents);
        $this->assertStringNotContainsString('[object]', $contents);
    }

    private function assertNoSensitiveData(string $text): void
    {
        $this->assertStringNotContainsString(self::EMAIL, $text);
        $this->assertStringNotContainsString(self::CPF, $text);
        $this->assertStringNotContainsStringIgnoringCase('insert into', $text);
        $this->assertStringNotContainsString('Duplicate entry', $text);
        $this->assertStringNotContainsString('abc123', $text);
    }
}

class M47FailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Expected response code 250 but got 550 for fulana.segredo@exemplo.com (CPF 52998224725)');
    }
}
