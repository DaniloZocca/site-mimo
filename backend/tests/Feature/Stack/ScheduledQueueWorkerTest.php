<?php

declare(strict_types=1);

namespace Tests\Feature\Stack;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-62: prova que os agendamentos fazem o trabalho de verdade dentro do próprio processo (sem proc_open), não só
 * que estão agendados: o worker esvazia a fila `database` e a limpeza apaga failed_jobs com mais de 7 dias.
 */
class ScheduledQueueWorkerTest extends CharacterizationTestCase
{
    protected bool $fakeQueue = false;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', null);
        config()->set('queue.failed.database', config('database.default'));
    }

    public function test_agendamento_da_fila_processa_os_jobs_e_volta(): void
    {
        dispatch(fn () => Cache::put('m62-job-rodou', true));
        $this->assertSame(1, DB::table('jobs')->count());

        $this->eventNamed('fila')->run($this->app);

        $this->assertTrue(Cache::get('m62-job-rodou'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_agendamento_da_limpeza_apaga_so_failed_jobs_com_mais_de_7_dias(): void
    {
        foreach (['velho' => now()->subHours(169), 'recente' => now()->subHours(167)] as $uuid => $failedAt) {
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => '{}',
                'failed_at' => $failedAt,
            ]);
        }

        $this->eventNamed('limpeza-failed-jobs')->run($this->app);

        $this->assertSame(['recente'], DB::table('failed_jobs')->pluck('uuid')->all());
    }

    private function eventNamed(string $name): Event
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => $event->description === $name,
        ));
        $this->assertCount(1, $events);

        return $events[0];
    }
}
