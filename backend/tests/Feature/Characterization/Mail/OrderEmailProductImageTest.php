<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Mail;

use App\Enums\OrderStatus;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPrintingStartedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-68: na lista de peças dos e-mails "pedido confirmado" e "impressão iniciada", a foto principal do
 * produto (URL absoluta da API) aparece no lugar do ícone; produto sem foto continua com o ícone.
 */
#[Group('characterization')]
class OrderEmailProductImageTest extends CharacterizationTestCase
{
    private const ICONE = '🧱';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://api.mimoo.exemplo.test');
        config()->set('app.frontend_url', 'https://mimoo.exemplo.test');
    }

    /**
     * @return list<class-string>
     */
    private function mailsComListaDePecas(): array
    {
        return [OrderConfirmedMail::class, OrderPrintingStartedMail::class];
    }

    public function test_foto_principal_do_produto_aparece_com_url_absoluta_no_lugar_do_icone(): void
    {
        $product = Product::factory()->create();
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'products/segunda.jpg', 'display_order' => 2]);
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'products/elden-ring.webp', 'display_order' => 1]);

        $order = Order::factory()->status(OrderStatus::PRINTING)->create();
        OrderItem::factory()->for($order)->for($product)->create(['product_title_snapshot' => 'Elden Ring Teste']);

        foreach ($this->mailsComListaDePecas() as $mailClass) {
            $html = (new $mailClass($order->fresh()))->render();

            $this->assertStringContainsString(
                '<img src="https://api.mimoo.exemplo.test/storage/products/elden-ring.webp" width="46" height="46" alt="Elden Ring Teste"',
                $html,
                $mailClass,
            );
            $this->assertStringNotContainsString('segunda.jpg', $html, $mailClass);
            $this->assertStringNotContainsString(self::ICONE, $html, $mailClass);
        }
    }

    public function test_produto_sem_foto_ou_apagado_continua_com_o_icone(): void
    {
        $order = Order::factory()->status(OrderStatus::PRINTING)->create();
        OrderItem::factory()->for($order)->create(['product_title_snapshot' => 'Sem Foto']);
        OrderItem::factory()->for($order)->create(['product_id' => null, 'product_title_snapshot' => 'Produto Apagado']);

        foreach ($this->mailsComListaDePecas() as $mailClass) {
            $html = (new $mailClass($order->fresh()))->render();

            $this->assertSame(2, substr_count($html, self::ICONE), $mailClass);
            $this->assertStringNotContainsString('/storage/', $html, $mailClass);
            $this->assertStringContainsString('Produto Apagado', $html, $mailClass);
        }
    }

    public function test_cada_item_recebe_a_foto_do_seu_proprio_produto(): void
    {
        $comFoto = Product::factory()->create();
        ProductImage::create(['product_id' => $comFoto->id, 'image_path' => 'products/vaso.png', 'display_order' => 0]);
        $semFoto = Product::factory()->create();

        $order = Order::factory()->status(OrderStatus::PRINTING)->create();
        OrderItem::factory()->for($order)->for($comFoto)->create(['product_title_snapshot' => 'Vaso']);
        OrderItem::factory()->for($order)->for($semFoto)->create(['product_title_snapshot' => 'Chaveiro']);

        $html = (new OrderConfirmedMail($order->fresh()))->render();

        $this->assertSame(1, substr_count($html, 'https://api.mimoo.exemplo.test/storage/products/vaso.png'));
        $this->assertSame(1, substr_count($html, self::ICONE));
    }

    public function test_caminho_ja_absoluto_e_mantido_e_endereco_sem_http_vira_icone(): void
    {
        $externo = Product::factory()->create();
        ProductImage::create(['product_id' => $externo->id, 'image_path' => 'https://cdn.exemplo.test/peca.jpg', 'display_order' => 0]);

        $order = Order::factory()->status(OrderStatus::PRINTING)->create();
        OrderItem::factory()->for($order)->for($externo)->create();

        $this->assertStringContainsString('src="https://cdn.exemplo.test/peca.jpg"', (new OrderConfirmedMail($order->fresh()))->render());

        // APP_URL sem esquema (configuração errada): sem URL absoluta, o e-mail não leva imagem quebrada.
        config()->set('app.url', 'api.mimoo.exemplo.test');
        $local = Product::factory()->create();
        ProductImage::create(['product_id' => $local->id, 'image_path' => 'products/x.jpg', 'display_order' => 0]);
        $outro = Order::factory()->status(OrderStatus::PRINTING)->create();
        OrderItem::factory()->for($outro)->for($local)->create();

        $html = (new OrderConfirmedMail($outro->fresh()))->render();
        $this->assertStringNotContainsString('products/x.jpg', $html);
        $this->assertStringContainsString(self::ICONE, $html);
    }

    public function test_template_nao_consulta_o_banco_para_a_foto(): void
    {
        $product = Product::factory()->create();
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'products/a.jpg', 'display_order' => 0]);
        $order = Order::factory()->status(OrderStatus::PRINTING)->create();
        OrderItem::factory()->for($order)->for($product)->create(['size_name_snapshot' => 'Pequeno']);

        $mail = new OrderConfirmedMail($order->fresh());
        $content = $mail->content(); // fotos carregadas aqui, antes do template

        DB::enableQueryLog();
        DB::flushQueryLog();
        $html = view($content->view, $content->with)->render();

        $this->assertStringContainsString('products/a.jpg', $html);
        $this->assertSame([], DB::getQueryLog());
    }
}
