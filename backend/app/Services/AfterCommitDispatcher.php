<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos que saem DEPOIS de a mudança de status estar gravada (eventos, listeners, e-mails, jobs).
 *
 * Roda depois do commit da transação mais externa (fora de transação, na hora). Uma falha aqui não desfaz nem
 * esconde a mudança de status e não pode mudar a resposta ao cliente (com QUEUE_CONNECTION=sync os listeners rodam
 * dentro da requisição): é registrada com Log::error, sem a mensagem da exceção (pode trazer o e-mail do cliente,
 * regra 8), e segue. Nunca use para o que roda antes ou durante a gravação do status.
 */
class AfterCommitDispatcher
{
    public function event(Order $order, object $event): void
    {
        DB::afterCommit(fn () => $this->guard($order, $event, fn () => event($event)));
    }

    public function job(Order $order, object $job): void
    {
        DB::afterCommit(fn () => $this->guard($order, $job, fn () => dispatch($job)));
    }

    private function guard(Order $order, object $subject, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::error('Falha depois da mudança de status: aviso não entregue.', [
                'order_number' => $order->order_number,
                'event' => $subject::class,
                'listener' => self::failingClass($e),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Primeiro listener ou job da aplicação no rastro da exceção (null se não houver).
     */
    private static function failingClass(Throwable $e): ?string
    {
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'App\\Listeners\\') || str_starts_with($class, 'App\\Jobs\\')) {
                return $class;
            }
        }

        return null;
    }
}
