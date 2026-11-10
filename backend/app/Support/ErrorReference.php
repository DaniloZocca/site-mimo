<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Registro de erro inesperado sem dados pessoais (M-46).
 *
 * A mensagem de uma exceção pode trazer o SQL com os valores preenchidos (e-mail, CPF, endereço) ou o
 * "Duplicate entry 'fulana@exemplo.com'" do banco. Por isso o log de um erro inesperado guarda só:
 * um código curto (o mesmo mostrado ao cliente, para o suporte achar a linha), a classe da exceção,
 * o SQLSTATE e o código do driver quando for erro de banco, e o primeiro arquivo:linha dentro de app/.
 * Nunca a mensagem, o SQL, os bindings ou o trace.
 */
final class ErrorReference
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

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
     * Texto ao cliente com o código do erro no final.
     */
    public static function appendTo(string $message, string $ref): string
    {
        return "{$message} (código do erro: {$ref})";
    }

    /**
     * Contexto de log seguro para a exceção.
     *
     * @return array{error_ref: string|null, exception: class-string, sqlstate: string|null, driver_code: int|string|null, location: string|null}
     */
    public static function context(Throwable $e, ?string $ref = null): array
    {
        [$sqlState, $driverCode] = self::databaseCodes($e);

        return [
            'error_ref' => $ref,
            'exception' => $e::class,
            'sqlstate' => $sqlState,
            'driver_code' => $driverCode,
            'location' => self::firstAppLocation($e),
        ];
    }

    /**
     * SQLSTATE e código do driver (ex.: 23000/1062, 40001/1213). Só os códigos: o terceiro item do
     * errorInfo é a mensagem do banco, que pode ter o valor recusado.
     *
     * @return array{0: string|null, 1: int|string|null}
     */
    private static function databaseCodes(Throwable $e): array
    {
        $pdo = match (true) {
            $e instanceof QueryException, $e instanceof PDOException => $e,
            $e->getPrevious() instanceof QueryException, $e->getPrevious() instanceof PDOException => $e->getPrevious(),
            default => null,
        };

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
        $appPath = rtrim(app_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if (is_string($file) && str_starts_with($file, $appPath)) {
                $relative = 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($appPath)));

                return $relative.':'.($frame['line'] ?? '?');
            }
        }

        return null;
    }
}
