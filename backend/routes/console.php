<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// M-45: na hospedagem compartilhada (Hostinger) não há `queue:work` rodando o tempo todo. O cron chama
// `schedule:run` a cada minuto e este worker esvazia a fila (e-mails) e sai antes do minuto seguinte.
// `withoutOverlapping`: nunca dois workers juntos se um minuto demorar mais (trava na tabela cache_locks).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

// M-54: o failed_jobs.payload guarda o job inteiro (num e-mail de conta, o destinatário: e-mail e nome).
// Depois de 7 dias sem `queue:retry`, a linha é apagada (dá tempo de reenviar depois de um fim de semana).
Schedule::command('queue:prune-failed --hours=168')->daily();
