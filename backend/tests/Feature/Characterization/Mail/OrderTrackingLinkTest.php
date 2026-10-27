<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Mail;

use App\Enums\OrderStatus;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPrintingStartedMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\OrderItem;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-27: o botão "Acompanhar" e o link da loja no rodapé dos e-mails de pedido abrem a loja (FRONTEND_URL),
 * não o endereço da API (APP_URL). Termos/Privacidade do rodapé: ver LegalPages/EmailFooterLegalLinksTest (M-41).
 */
#[Group('characterization')]
class OrderTrackingLinkTest extends CharacterizationTestCase
{
    public function test_botao_acompanhar_aponta_para_a_loja_com_o_token_do_pedido(): void
    {
        config()->set('app.url', 'https://api.mimoo.exemplo.test');
        config()->set('app.frontend_url', 'https://mimoo.exemplo.test');

        $order = Order::factory()->status(OrderStatus::SHIPPED)->create(['carrier_tracking_code' => 'BR123456789BR']);
        OrderItem::factory()->for($order)->create();

        foreach ([new OrderConfirmedMail($order), new OrderPrintingStartedMail($order), new OrderShippedMail($order)] as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString("href=\"https://mimoo.exemplo.test/?token={$order->tracking_token}\"", $html, $mail::class);
            // O link "mimoolab.com.br" do rodapé também leva à loja.
            $this->assertStringContainsString('href="https://mimoo.exemplo.test"', $html, $mail::class);
            $this->assertStringNotContainsString('href="https://api.mimoo.exemplo.test"', $html, $mail::class);
            $this->assertStringNotContainsString('api.mimoo.exemplo.test/?token', $html, $mail::class);
        }
    }

    public function test_endereco_da_loja_vem_do_env_frontend_url_sem_barra_final(): void
    {
        $original = [$_ENV['FRONTEND_URL'] ?? null, $_SERVER['FRONTEND_URL'] ?? null, getenv('FRONTEND_URL')];

        try {
            $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = 'https://mimoo.exemplo.test/';
            putenv('FRONTEND_URL=https://mimoo.exemplo.test/');
            $this->assertSame('https://mimoo.exemplo.test', (require config_path('app.php'))['frontend_url']);

            unset($_ENV['FRONTEND_URL'], $_SERVER['FRONTEND_URL']);
            putenv('FRONTEND_URL');
            $this->assertSame('http://localhost:5173', (require config_path('app.php'))['frontend_url']);
        } finally {
            unset($_ENV['FRONTEND_URL'], $_SERVER['FRONTEND_URL']);
            putenv('FRONTEND_URL');
            if ($original[0] !== null) {
                $_ENV['FRONTEND_URL'] = $original[0];
            }
            if ($original[1] !== null) {
                $_SERVER['FRONTEND_URL'] = $original[1];
            }
            if ($original[2] !== false) {
                putenv("FRONTEND_URL={$original[2]}");
            }
        }
    }
}
