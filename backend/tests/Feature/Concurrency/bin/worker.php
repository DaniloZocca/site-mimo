<?php

declare(strict_types=1);

/*
 * M-45: segundo processo PHP dos testes de concorrência (grupo `concurrency`). Abre a própria conexão com o
 * MariaDB, faz UMA operação real da loja e imprime o resultado em JSON na última linha. Só existe nos testes.
 *
 * Uso: php worker.php <cenário> '<json>'
 */

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\Feature\Concurrency\Support\Harness;
use Tests\Feature\Concurrency\Support\WorkerScenarios;

$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, fn ($app) => Harness::configure($app['config']));

$scenario = (string) ($argv[1] ?? '');
$args = (array) json_decode((string) ($argv[2] ?? '{}'), true);

$started = microtime(true);
$result = (new WorkerScenarios($app))->run($scenario, $args);
$result['ms'] = (int) round((microtime(true) - $started) * 1000);

echo PHP_EOL.json_encode($result, JSON_UNESCAPED_UNICODE).PHP_EOL;
