<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Logging;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-54: o comando agendado (routes/console.php) apaga do failed_jobs as linhas com mais de 7 dias, também com o
 * provider próprio (PrivacySafeFailedJobProvider herda o prune do DatabaseUuidFailedJobProvider).
 */
#[Group('characterization')]
class FailedJobsPruneTest extends CharacterizationTestCase
{
    public function test_prune_apaga_so_as_linhas_com_mais_de_7_dias(): void
    {
        config(['queue.failed.database' => 'sqlite']);
        $this->app->forgetInstance('queue.failer');

        $old = $this->insertFailed(Date::now()->subHours(169));
        $recent = $this->insertFailed(Date::now()->subHours(167));

        Artisan::call('queue:prune-failed', ['--hours' => 168]);

        $this->assertSame([$recent], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertNotSame($old, $recent);
    }

    private function insertFailed(\DateTimeInterface $failedAt): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => '{}',
            'failed_at' => $failedAt,
        ]);

        return $uuid;
    }
}
