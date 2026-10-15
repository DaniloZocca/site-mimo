<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ShippingCalculationService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * M-31: o log da cotação de frete não pode trazer o CEP completo (regra 8 do CLAUDE.md).
 */
class ShippingLogCepMaskTest extends TestCase
{
    public function test_log_do_fallback_mostra_so_os_5_primeiros_digitos_do_cep(): void
    {
        config(['services.shipping.melhor_envio.token' => '']);

        /** @var array<int, MessageLogged> $logs */
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $options = app(ShippingCalculationService::class)->calculate('01001-000', []);

        $this->assertNotEmpty($options);

        $messages = array_map(fn (MessageLogged $e): string => $e->message, $logs);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('CEP 01001-***', $messages[0]);
        $this->assertStringNotContainsString('01001000', $messages[0]);
        $this->assertStringNotContainsString('01001-000', $messages[0]);
    }
}
