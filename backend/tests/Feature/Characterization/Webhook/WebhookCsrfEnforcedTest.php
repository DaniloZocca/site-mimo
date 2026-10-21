<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Webhook;

use App\Enums\OrderStatus;
use App\Events\OrderPaidEvent;
use App\Models\Order;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;
use Tests\Feature\Characterization\Support\FakeMercadoPagoHttpClient;
use Tests\Feature\Characterization\Support\SendsMercadoPagoWebhooks;

/**
 * M-35: com o CSRF do Laravel 13 (PreventRequestForgery) ligado de verdade, o webhook do Mercado Pago
 * continua entrando só pela assinatura: sem token CSRF, sem Origin e sem Sec-Fetch-Site.
 */
#[Group('characterization')]
class WebhookCsrfEnforcedTest extends CharacterizationTestCase
{
    use SendsMercadoPagoWebhooks;

    public function test_webhook_assinado_sem_token_nem_origin_aprova_o_pedido_com_csrf_ligado(): void
    {
        Event::fake([OrderPaidEvent::class]);
        $this->enforceCsrf();

        // Prova de que o CSRF está ligado: rota com sessão e sem token dá 419
        $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'gatinho123'])->assertStatus(419);

        $order = Order::factory()->create(['total_cents' => 10000]);
        $this->mpHttp->pushJson(FakeMercadoPagoHttpClient::payment(123, 'approved', $order->order_number, 100.0), 200);

        // postJson não manda Origin nem Sec-Fetch-Site; só os cabeçalhos da assinatura
        $this->postSignedWebhook('123')
            ->assertOk()
            ->assertJsonPath('order_status', 'in_queue');

        $this->assertSame(OrderStatus::IN_QUEUE, $order->refresh()->status);
        Event::assertDispatchedTimes(OrderPaidEvent::class, 1);
    }

    /** Mesmo harness do CustomerSessionTest: desliga o atalho que pula o CSRF nos testes. */
    private function enforceCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn (Application $app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
