<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Console\Commands\CheckProductionConfigCommand;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-62: o PHP da Hostinger desliga proc_open e symlink. O `mimoo:check-production` reprova agendamento que
 * precisaria de processo filho e avisa quando falta o link public/storage das fotos.
 */
class CheckProductionHostingerTest extends CharacterizationTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/m62-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->dir.'/storage/app/public', 0755, true);
        File::makeDirectory($this->dir.'/public', 0755, true);
        File::makeDirectory($this->dir.'/outro', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_agendamento_com_processo_filho_reprova_sem_proc_open(): void
    {
        $mutex = $this->app->make(CacheEventMutex::class);
        $events = [
            new CallbackEvent($mutex, fn () => null),
            (new Event($mutex, 'php artisan inspire'))->description('exemplo'),
        ];

        $problems = CheckProductionConfigCommand::schedulerProblems($events, procOpenAvailable: false);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Agendamento "exemplo" precisa de proc_open', $problems[0]);
    }

    public function test_agendamento_com_processo_filho_passa_com_proc_open(): void
    {
        $event = new Event($this->app->make(CacheEventMutex::class), 'php artisan inspire');

        $this->assertSame([], CheckProductionConfigCommand::schedulerProblems([$event], procOpenAvailable: true));
    }

    public function test_agendamentos_da_loja_passam_sem_proc_open(): void
    {
        $this->assertSame([], CheckProductionConfigCommand::schedulerProblems(
            app(Schedule::class)->events(),
            procOpenAvailable: false,
        ));
    }

    public function test_link_das_fotos_ausente_avisa_com_o_comando_ln(): void
    {
        $warning = CheckProductionConfigCommand::publicStorageWarning($this->dir.'/public/storage', $this->dir.'/storage/app/public');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('ln -s ~/site-mimo/backend/storage/app/public ~/site-mimo/backend/public/storage', $warning);
    }

    public function test_link_das_fotos_para_outro_lugar_avisa(): void
    {
        symlink($this->dir.'/outro', $this->dir.'/public/storage');

        $this->assertNotNull(CheckProductionConfigCommand::publicStorageWarning($this->dir.'/public/storage', $this->dir.'/storage/app/public'));
    }

    public function test_pasta_comum_no_lugar_do_link_avisa(): void
    {
        File::makeDirectory($this->dir.'/public/storage');

        $this->assertNotNull(CheckProductionConfigCommand::publicStorageWarning($this->dir.'/public/storage', $this->dir.'/storage/app/public'));
    }

    public function test_link_das_fotos_certo_nao_avisa(): void
    {
        symlink($this->dir.'/storage/app/public', $this->dir.'/public/storage');

        $this->assertNull(CheckProductionConfigCommand::publicStorageWarning($this->dir.'/public/storage', $this->dir.'/storage/app/public'));
    }
}
