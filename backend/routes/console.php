<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// M-62: o PHP da Hostinger desliga proc_open. `Schedule::command()` sobe cada comando como processo filho e lá
// não roda nada (o schedule:run ainda imprime DONE). Por isso todo agendamento é `Schedule::call()` e chama o
// comando DENTRO do próprio processo com `Artisan::call()`. O QueueScheduleTest reprova qualquer outro tipo.
// A limpeza vem ANTES do worker: se um job estourar o tempo, o Laravel encerra o processo inteiro e o que viesse
// depois do worker naquele minuto não rodaria.

// M-54: o failed_jobs.payload guarda o job inteiro (num e-mail de conta, o destinatário: e-mail e nome).
// Depois de 7 dias sem `queue:retry`, a linha é apagada (dá tempo de reenviar depois de um fim de semana).
Schedule::call(fn () => Artisan::call('queue:prune-failed', ['--hours' => 168]))
    ->name('limpeza-failed-jobs')
    ->daily();

// M-45: na hospedagem compartilhada (Hostinger) não há `queue:work` rodando o tempo todo. O cron chama
// `schedule:run` a cada minuto e este worker esvazia a fila (e-mails) e sai antes do minuto seguinte.
// `withoutOverlapping`: nunca dois workers juntos se um minuto demorar mais (trava na tabela cache_locks).
Schedule::call(fn () => Artisan::call('queue:work', [
    '--stop-when-empty' => true,
    '--max-time' => 50,
    '--tries' => 3,
]))
    ->name('fila')
    ->everyMinute()
    ->withoutOverlapping(5);
