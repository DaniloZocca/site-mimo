<?php

declare(strict_types=1);

namespace Tests\Feature\Stack;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * M-45: na Hostinger não há `queue:work` permanente; o cron chama `schedule:run` a cada minuto e o agendador
 * sobe um worker que esvazia a fila e sai antes do minuto seguinte, sem dois workers juntos.
 */
class QueueScheduleTest extends TestCase
{
    public function test_worker_da_fila_roda_a_cada_minuto_sem_sobreposicao(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));

        $this->assertCount(1, $events);

        /** @var Event $worker */
        $worker = $events->first();
        $this->assertStringEndsWith("'artisan' queue:work --stop-when-empty --max-time=50 --tries=3", (string) $worker->command);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertSame(5, $worker->expiresAt);
    }
}
