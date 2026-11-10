<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Logging;

use App\Services\ShippingCalculationService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-49: quando o Melhor Envio responde erro, o log não grava o corpo da resposta (que pode repetir o CEP
 * e os dados do envio). Vai só o status HTTP e o código do ErrorReference. Os testes leem o ARQUIVO de log
 * de verdade (canal single), além do evento, para pegar o que o formatador do Monolog escreveria.
 *
 * Hoje o cliente HTTP usa retry(2, 100), que lança RequestException depois da última tentativa: a falha cai
 * no catch (ErrorReference::context, sem a mensagem, que traz o corpo). O teste cobre o caminho real, seja ele
 * o catch ou o ramo de status sem sucesso.
 */
#[Group('characterization')]
class ShippingErrorLogPrivacyTest extends CharacterizationTestCase
{
    private const CEP = '04538133';

    private const CEP_FORMATADO = '04538-133';

    private const SEGREDO_DO_CORPO = 'Rua Segredo do Corpo 123';

    private string $logPath;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'm49-log-');
        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
            'services.shipping.melhor_envio.token' => 'token-melhor-envio-teste',
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorStatuses(): array
    {
        return [
            'HTTP 422 (CEP recusado)' => [422],
            'HTTP 500 (erro do Melhor Envio)' => [500],
        ];
    }

    #[DataProvider('errorStatuses')]
    public function test_erro_do_melhor_envio_nao_grava_o_corpo_nem_o_cep_no_log(int $status): void
    {
        Http::fake(['*/api/v2/me/shipment/calculate' => Http::response([
            'message' => 'O CEP '.self::CEP_FORMATADO.' ('.self::CEP.') é inválido.',
            'errors' => ['to.postal_code' => [self::CEP], 'to.address' => [self::SEGREDO_DO_CORPO]],
        ], $status)]);

        $options = app(ShippingCalculationService::class)->calculate(self::CEP, []);

        // Continua caindo na tabela de reserva, como antes
        $this->assertTrue($options[0]->metadata['fallback']);

        $this->assertNotEmpty($this->logged);
        $everything = json_encode($this->logged, JSON_UNESCAPED_UNICODE).(string) file_get_contents($this->logPath);

        foreach ([self::CEP, self::CEP_FORMATADO, self::SEGREDO_DO_CORPO, 'postal_code', 'inválido'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything);
        }

        $entry = collect($this->logged)->first(fn (array $e): bool => str_contains($e['message'], 'Melhor Envio'));
        $this->assertNotNull($entry, 'A falha do Melhor Envio tem de continuar aparecendo no log.');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', (string) $entry['context']['error_ref']);
        $this->assertStringStartsWith('app/Services/ShippingCalculationService.php:', (string) $entry['context']['location']);
        $this->assertSame($status, $entry['context']['http_status']);
        $this->assertArrayNotHasKey('message_safe', $entry['context']);
    }
}
