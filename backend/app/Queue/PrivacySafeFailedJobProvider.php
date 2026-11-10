<?php

declare(strict_types=1);

namespace App\Queue;

use App\Support\ErrorReference;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * failed_jobs sem dados pessoais (M-47).
 *
 * O Laravel grava na coluna `exception` o texto inteiro da exceção (mensagem + trace). Um e-mail que não
 * saiu deixaria ali o endereço do cliente, sem prazo. Aqui a coluna recebe o mesmo contexto seguro do log
 * (ErrorReference, em JSON): código do erro, classe, SQLSTATE, frames em app/ e a mensagem higienizada só
 * para erros do PHP. O `payload` não muda, então `queue:retry` e `queue:failed` seguem funcionando.
 */
final class PrivacySafeFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  Throwable  $exception
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $this->getTable()->insert([
            'uuid' => $uuid = json_decode($payload, true)['uuid'],
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => (string) json_encode(
                ErrorReference::context($exception, ErrorReference::for($exception)),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'failed_at' => Date::now(),
        ]);

        return $uuid;
    }
}
