<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Support;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/**
 * Captura as mensagens de log (nível + texto) via evento MessageLogged.
 * Preferido a Log::spy() porque permite afirmar o NÍVEL sem acoplar à ordem de chamadas.
 */
trait CapturesLogs
{
    /** @var array<int, MessageLogged> */
    protected array $capturedLogs = [];

    protected function captureLogs(): void
    {
        $this->capturedLogs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->capturedLogs[] = $event;
        });
    }

    /**
     * @param  array<int, string>  $levels
     */
    protected function logsAtLevels(array $levels, ?string $contains = null): array
    {
        return array_values(array_filter(
            $this->capturedLogs,
            fn (MessageLogged $e): bool => in_array($e->level, $levels, true)
                && ($contains === null || str_contains($e->message, $contains))
        ));
    }
}
