<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPages;

use App\Enums\OrderStatus;
use App\Mail\CustomerVerifyEmailMail;
use App\Mail\OrderConfirmedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Mail\Mailable;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-41, itens 4 e 5: o rodapé dos e-mails leva Termos e Política para as páginas da loja (FRONTEND_URL/?pagina=…),
 * não para a API, e a linha com os dados da loja vem de config/loja.php, sem dado inventado.
 */
class EmailFooterLegalLinksTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://api.mimoo.exemplo.test');
        config()->set('app.frontend_url', 'https://mimoo.exemplo.test');
    }

    public function test_termos_e_politica_abrem_as_paginas_da_loja(): void
    {
        foreach ($this->mails() as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('href="https://mimoo.exemplo.test/?pagina=termos"', $html, $mail::class);
            $this->assertStringContainsString('href="https://mimoo.exemplo.test/?pagina=privacidade"', $html, $mail::class);
            $this->assertStringNotContainsString('api.mimoo.exemplo.test/termos', $html, $mail::class);
            $this->assertStringNotContainsString('api.mimoo.exemplo.test/privacidade', $html, $mail::class);
        }
    }

    public function test_linha_da_loja_mostra_os_dados_configurados(): void
    {
        config()->set('loja.razao_social', 'Exemplo Impressões Ltda');
        config()->set('loja.cnpj', '11.222.333/0001-81');
        config()->set('loja.cidade_uf', 'Campinas / SP');

        foreach ($this->mails() as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Exemplo Impressões Ltda • CNPJ: 11.222.333/0001-81 • Campinas / SP — Brasil', $html, $mail::class);
            $this->assertStringNotContainsString('00.000.000/0001-00', $html, $mail::class);
        }
    }

    public function test_sem_dados_da_loja_a_linha_some_e_nada_e_inventado(): void
    {
        config()->set('loja.razao_social', '');
        config()->set('loja.cnpj', '');
        config()->set('loja.cidade_uf', '');

        foreach ($this->mails() as $mail) {
            $html = $mail->render();

            $this->assertStringNotContainsString('CNPJ', $html, $mail::class);
            $this->assertStringNotContainsString('00.000.000/0001-00', $html, $mail::class);
            $this->assertStringNotContainsString('São Paulo / SP', $html, $mail::class);
            $this->assertStringNotContainsString('— Brasil', $html, $mail::class);
        }
    }

    public function test_so_o_cnpj_preenchido_aparece_sozinho(): void
    {
        config()->set('loja.razao_social', '');
        config()->set('loja.cnpj', '11.222.333/0001-81');
        config()->set('loja.cidade_uf', '');

        $html = (new OrderConfirmedMail($this->order()))->render();

        $this->assertMatchesRegularExpression('/>\s*CNPJ: 11\.222\.333\/0001-81\s*</', $html);
    }

    /** @return list<Mailable> */
    private function mails(): array
    {
        return [
            new OrderConfirmedMail($this->order()),
            new CustomerVerifyEmailMail(Customer::factory()->unverified()->create()),
        ];
    }

    private function order(): Order
    {
        $order = Order::factory()->status(OrderStatus::IN_QUEUE)->create();
        OrderItem::factory()->for($order)->create();

        return $order;
    }
}
