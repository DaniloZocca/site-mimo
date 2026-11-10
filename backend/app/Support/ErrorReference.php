<?php

declare(strict_types=1);

namespace App\Support;

use BadMethodCallException;
use Error;
use ErrorException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use PDOException;
use Throwable;
use WeakMap;

/**
 * Registro de erro inesperado sem dados pessoais (M-46).
 *
 * A mensagem de uma exceção pode trazer o SQL com os valores preenchidos (e-mail, CPF, endereço) ou o
 * "Duplicate entry 'fulana@exemplo.com'" do banco. Por isso o log de um erro inesperado guarda só:
 * um código curto (o mesmo mostrado ao cliente, para o suporte achar a linha), a classe da exceção,
 * o SQLSTATE e o código do driver quando for erro de banco, e o primeiro arquivo:linha dentro de app/.
 * Nunca a mensagem, o SQL, os bindings ou o trace.
 *
 * M-47: o mesmo contexto vale para todo erro não tratado (bootstrap/app.php) e para a coluna
 * failed_jobs.exception. Acrescenta até 5 frames dentro de app/ (Classe::método arquivo:linha, sem
 * argumentos) e, só para erros do PHP (Error, ErrorException, BadMethodCallException), a mensagem
 * higienizada em message_safe. QueryException e as demais classes continuam sem mensagem.
 *
 * M-54: a decisão olha a cadeia inteira (getPrevious). Uma ViewException (herda de ErrorException) com uma
 * QueryException dentro carrega o SQL com os valores na mensagem: sai sem message_safe. SQLSTATE e código do
 * driver vêm do primeiro erro de banco em qualquer nível (template aninhado embrulha duas vezes).
 */
final class ErrorReference
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    private const MAX_FRAMES = 5;

    private const MAX_MESSAGE_LENGTH = 500;

    private const REDACTED = '[x]';

    /** Quantos níveis de getPrevious() são lidos (trava contra cadeia circular). */
    private const MAX_CHAIN = 10;

    /** Texto que só aparece em mensagem de erro de banco: nunca vira message_safe, venha de onde vier. */
    private const DATABASE_MARKERS = ['SQLSTATE[', 'SQL:'];

    /**
     * Classes cuja mensagem é texto técnico do PHP (tipo errado, chave inexistente, método indefinido) e
     * pode ir para o log depois de higienizada. Subclasses entram (TypeError, ArgumentCountError etc.).
     */
    private const SAFE_MESSAGE_CLASSES = [Error::class, ErrorException::class, BadMethodCallException::class];

    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $refs = null;

    /**
     * Código aleatório de 8 caracteres (sem 0/O e 1/I, para o cliente ditar sem confusão).
     */
    public static function generate(): string
    {
        $ref = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $ref .= self::ALPHABET[random_int(0, $last)];
        }

        return $ref;
    }

    /**
     * O mesmo código para a mesma exceção: o log (report) e a resposta 500 (render) mostram o mesmo.
     */
    public static function for(Throwable $e): string
    {
        self::$refs ??= new WeakMap;

        return self::$refs[$e] ??= self::generate();
    }

    /**
     * O código já dado a esta exceção, ou null se ela não foi registrada no log.
     */
    public static function existing(Throwable $e): ?string
    {
        return self::$refs[$e] ?? null;
    }

    /**
     * Onde o erro aconteceu, sem valores: método + molde da rota (ex.: "GET api/orders/{order_number}").
     * Fora de uma rota (comando, fila): "console". Os valores da URL podem ter token e nunca entram.
     */
    public static function route(): string
    {
        $route = app()->bound('request') ? request()->route() : null;

        if ($route instanceof Route) {
            return request()->getMethod().' '.$route->uri();
        }

        return app()->runningInConsole() ? 'console' : 'http (sem rota)';
    }

    /**
     * Texto ao cliente com o código do erro no final.
     */
    public static function appendTo(string $message, string $ref): string
    {
        return "{$message} (código do erro: {$ref})";
    }

    /**
     * Contexto de log seguro para a exceção.
     *
     * @return array{error_ref: string|null, exception: class-string, sqlstate: string|null, driver_code: int|string|null, location: string|null, frames: list<string>, message_safe?: string}
     */
    public static function context(Throwable $e, ?string $ref = null): array
    {
        [$sqlState, $driverCode] = self::databaseCodes($e);

        $context = [
            'error_ref' => $ref,
            'exception' => $e::class,
            'sqlstate' => $sqlState,
            'driver_code' => $driverCode,
            'location' => self::firstAppLocation($e),
            'frames' => self::appFrames($e),
        ];

        if (self::hasSafeMessage($e)) {
            $context['message_safe'] = self::sanitizeMessage($e->getMessage());
        }

        return $context;
    }

    /**
     * Mensagem de erro do PHP sem valores: troca por [x] URLs com query string (a parte depois do "?"),
     * usuário/senha de URL, textos entre aspas, e-mails (também sem ponto no domínio), tokens longos (20+
     * caracteres com letra e número, inclui UUID), números com pontuação ou espaço (CPF, CNPJ, CEP, telefone) e
     * sequências de 4+ dígitos. Caminho absoluto do servidor vira relativo à raiz do backend. O texto técnico
     * ("must be of type int, string given") fica.
     */
    public static function sanitizeMessage(string $message): string
    {
        $app = app();
        if ($app instanceof Application) {
            $message = str_replace(rtrim($app->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, '', $message);
        }

        $patterns = [
            '/\?[^\s\'"?=&#]+=[^\s\'"]*/u' => '?'.self::REDACTED,          // query string (?chave=valor...)
            '#(?<=//)[^/\s@\'"]+@#u' => self::REDACTED.'@',                 // usuário:senha@ de URL
            '/\'[^\']*\'|"[^"]*"/u' => self::REDACTED,                       // entre aspas simples ou duplas
            '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+/u' => self::REDACTED,      // e-mail (com ou sem ponto no domínio)
            '/(?<![\p{L}\p{N}_\-])(?=[\p{L}\p{N}_\-]*\p{N})(?=[\p{L}\p{N}_\-]*\p{L})[\p{L}\p{N}_\-]{20,}/u' => self::REDACTED, // token/UUID
            '/\d+(?:[.\-\/]\d+)+/u' => self::REDACTED,                        // 123.456.789-09, 01310-930, 12.345.678/0001-90
            '/\d{2,}(?: \d{2,})+/u' => self::REDACTED,                        // 529 982 247 25, 98765 4321
            '/\d{4,}/u' => self::REDACTED,                                   // 4 ou mais dígitos seguidos
        ];

        $clean = (string) preg_replace(array_keys($patterns), array_values($patterns), $message);

        return mb_strlen($clean) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($clean, 0, self::MAX_MESSAGE_LENGTH).'…'
            : $clean;
    }

    /**
     * Só quando a exceção e todas as de dentro são erros do PHP (nenhuma de banco) e a mensagem não tem
     * marca de SQL. Ex.: ViewException(ErrorException "Undefined variable") tem; ViewException(QueryException) não.
     */
    private static function hasSafeMessage(Throwable $e): bool
    {
        foreach (self::chain($e) as $link) {
            if (self::isDatabaseException($link) || ! self::isSafeClass($link)) {
                return false;
            }
        }

        foreach (self::DATABASE_MARKERS as $marker) {
            if (str_contains($e->getMessage(), $marker)) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeClass(Throwable $e): bool
    {
        foreach (self::SAFE_MESSAGE_CLASSES as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function isDatabaseException(Throwable $e): bool
    {
        return $e instanceof QueryException || $e instanceof PDOException;
    }

    /**
     * A exceção e as de dentro (getPrevious), até MAX_CHAIN níveis.
     *
     * @return list<Throwable>
     */
    private static function chain(Throwable $e): array
    {
        $chain = [];

        for ($link = $e; $link !== null && count($chain) < self::MAX_CHAIN; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        return $chain;
    }

    /**
     * Até 5 frames dentro de app/, do ponto do throw para fora: "Classe::método app/arquivo.php:linha".
     * Só nomes e posições; os argumentos do trace nunca são lidos.
     *
     * @return list<string>
     */
    private static function appFrames(Throwable $e): array
    {
        $trace = $e->getTrace();
        // A posição i (arquivo:linha) está dentro da função chamada no frame i do trace.
        $positions = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$trace];
        $frames = [];

        foreach ($positions as $i => $position) {
            $relative = self::relativeAppPath($position['file'] ?? null);
            if ($relative === null) {
                continue;
            }

            $caller = $trace[$i] ?? [];
            $function = (string) ($caller['function'] ?? '{main}');
            $name = isset($caller['class']) ? $caller['class'].'::'.$function : $function;

            $frames[] = $name.' '.$relative.':'.($position['line'] ?? '?');

            if (count($frames) === self::MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }

    /**
     * SQLSTATE e código do driver (ex.: 23000/1062, 40001/1213) do primeiro erro de banco da cadeia. Só os códigos: o terceiro item do
     * errorInfo é a mensagem do banco, que pode ter o valor recusado.
     *
     * @return array{0: string|null, 1: int|string|null}
     */
    private static function databaseCodes(Throwable $e): array
    {
        $pdo = null;
        foreach (self::chain($e) as $link) {
            if (self::isDatabaseException($link)) {
                $pdo = $link;
                break;
            }
        }

        if ($pdo === null) {
            return [null, null];
        }

        $errorInfo = $pdo->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[0])) {
            return [(string) $errorInfo[0], $errorInfo[1] ?? null];
        }

        $code = $pdo->getCode();

        return [$code === 0 || $code === '' ? null : (string) $code, null];
    }

    /**
     * Primeiro arquivo:linha dentro de app/ (relativo à raiz do backend), olhando o ponto do throw e o trace.
     */
    private static function firstAppLocation(Throwable $e): ?string
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];

        foreach ($frames as $frame) {
            $relative = self::relativeAppPath($frame['file'] ?? null);
            if ($relative !== null) {
                return $relative.':'.($frame['line'] ?? '?');
            }
        }

        return null;
    }

    private static function relativeAppPath(mixed $file): ?string
    {
        $appPath = rtrim(app_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! is_string($file) || ! str_starts_with($file, $appPath)) {
            return null;
        }

        return 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($appPath)));
    }
}
