<?php

declare(strict_types=1);

namespace Tests\Feature\Stack;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * M-45: na Hostinger não há `queue:work` permanente; o cron chama `schedule:run` a cada minuto e o agendador
 * sobe um worker que esvazia a fila e sai antes do minuto seguinte, sem dois workers juntos.
 *
 * M-62: o PHP da Hostinger desliga proc_open, então nenhum agendamento pode ser processo filho
 * (`Schedule::command`): todos são `Schedule::call` que chamam o comando no próprio processo.
 */
class QueueScheduleTest extends TestCase
{
    public function test_worker_da_fila_roda_a_cada_minuto_sem_sobreposicao(): void
    {
        $worker = $this->eventNamed('fila');

        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertSame(5, $worker->expiresAt);
    }

    /**
     * M-54: failed_jobs.payload pode ter o e-mail e o nome do destinatário; não fica mais que 7 dias.
     */
    public function test_failed_jobs_com_mais_de_7_dias_sao_apagados_todo_dia(): void
    {
        $this->assertSame('0 0 * * *', $this->eventNamed('limpeza-failed-jobs')->expression);
    }

    public function test_nenhum_agendamento_depende_de_processo_filho(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertInstanceOf(
                CallbackEvent::class,
                $event,
                "O agendamento \"{$event->getSummaryForDisplay()}\" sobe um processo filho, e o PHP da Hostinger "
                .'desliga proc_open: ele nunca rodaria. Use Schedule::call() com Artisan::call() (M-62).'
            );
        }
    }

    /**
     * Se um job estourar o tempo, o Laravel encerra o processo inteiro: o que vier depois do worker não roda.
     */
    public function test_limpeza_vem_antes_do_worker(): void
    {
        $names = array_map(fn (Event $event): ?string => $event->description, app(Schedule::class)->events());

        $this->assertSame(['limpeza-failed-jobs', 'fila'], $names);
    }

    private function eventNamed(string $name): Event
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => $event->description === $name,
        ));

        $this->assertCount(1, $events, "Agendamento \"{$name}\" não encontrado em routes/console.php.");

        return $events[0];
    }
}
