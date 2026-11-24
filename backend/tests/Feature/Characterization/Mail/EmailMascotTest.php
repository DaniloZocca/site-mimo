<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Mail;

use App\Enums\OrderStatus;
use App\Mail\CustomerResetPasswordMail;
use App\Mail\CustomerSignupAttemptMail;
use App\Mail\CustomerVerifyEmailMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPrintingStartedMail;
use App\Mail\OrderShippedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\EmailBrandImage;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-70: o cabeçalho de todos os e-mails mostra a Vaquinha Mimoo do logo (imagem servida pela API,
 * URL absoluta) no lugar do emoji 🐮; os botões "Linha do Tempo MIMOO" e "Criar nova senha" também.
 * No e-mail de envio, os dois botões empilham no celular (classe stack-column) para não quebrar palavras.
 * Texto menos técnico para o cliente: sem "extrusão" nem "engenheiros".
 */
#[Group('characterization')]
class EmailMascotTest extends CharacterizationTestCase
{
    private const MASCOTE = 'https://api.mimoo.exemplo.test/images/email/vaquinha-mimoo.png';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://api.mimoo.exemplo.test/');
        config()->set('app.frontend_url', 'https://mimoo.exemplo.test');
    }

    /**
     * HTML do e-mail sem os marcadores <!--[if BLOCK]> que o Livewire injeta quando algum teste anterior
     * renderizou um componente no mesmo processo (comentários HTML, sem efeito no e-mail).
     */
    private function html(Mailable $mail): string
    {
        return (string) preg_replace('#<!--\[if (END)?BLOCK\]><!\[endif\]-->#', '', $mail->render());
    }

    /**
     * @return array<string, Mailable>
     */
    private function todosOsEmails(): array
    {
        $order = Order::factory()->status(OrderStatus::SHIPPED)->create(['carrier_tracking_code' => 'BR123456789BR']);
        OrderItem::factory()->for($order)->create();
        $order = $order->fresh();
        $customer = Customer::factory()->unverified()->create();

        return [
            'pedido confirmado' => new OrderConfirmedMail($order),
            'impressão iniciada' => new OrderPrintingStartedMail($order),
            'enviado' => new OrderShippedMail($order),
            'confirmar e-mail' => new CustomerVerifyEmailMail($customer),
            'nova senha' => new CustomerResetPasswordMail($customer, str_repeat('a', 64)),
            'tentativa de cadastro' => new CustomerSignupAttemptMail($customer),
        ];
    }

    public function test_arquivo_da_mascote_existe_na_pasta_publica_da_api(): void
    {
        $arquivo = public_path(EmailBrandImage::MASCOT_PATH);

        $this->assertFileExists($arquivo);
        $this->assertSame([112, 112, 'image/png'], array_values(array_intersect_key(getimagesize($arquivo), array_flip([0, 1, 'mime']))));
    }

    public function test_cabecalho_de_todos_os_emails_usa_a_mascote_no_lugar_do_emoji(): void
    {
        foreach ($this->todosOsEmails() as $nome => $mail) {
            $html = $this->html($mail);

            $this->assertStringContainsString(
                '<img src="'.self::MASCOTE.'" width="28" height="28" alt="Vaquinha Mimoo"',
                $html,
                $nome,
            );
            $this->assertStringNotContainsString('🐮 MIMOO LAB', $html, $nome);
        }
    }

    public function test_botoes_com_vaquinha_usam_a_imagem(): void
    {
        $emails = $this->todosOsEmails();

        $enviado = $this->html($emails['enviado']);
        $this->assertStringNotContainsString('Linha do Tempo MIMOO 🐮', $enviado);
        $this->assertMatchesRegularExpression('#Linha do Tempo MIMOO <img src="'.preg_quote(self::MASCOTE, '#').'" width="20" height="20" alt="Vaquinha Mimoo"#', $enviado);

        $senha = $this->html($emails['nova senha']);
        $this->assertStringNotContainsString('Criar nova senha 🐮', $senha);
        $this->assertMatchesRegularExpression('#Criar nova senha <img src="'.preg_quote(self::MASCOTE, '#').'" width="20" height="20"#', $senha);
    }

    public function test_botoes_do_email_de_envio_empilham_no_celular(): void
    {
        $html = $this->html($this->todosOsEmails()['enviado']);

        $this->assertSame(2, substr_count($html, 'class="stack-column"'));
        $this->assertStringContainsString('.stack-column { display: block !important; width: 100% !important;', $html);
    }

    public function test_app_url_sem_http_mantem_o_emoji_em_vez_de_imagem_quebrada(): void
    {
        config()->set('app.url', 'api.mimoo.exemplo.test');

        foreach ($this->todosOsEmails() as $nome => $mail) {
            $html = $this->html($mail);

            $this->assertStringNotContainsString('vaquinha-mimoo.png', $html, $nome);
            $this->assertMatchesRegularExpression('#🐮\s*<span style="vertical-align: middle;">MIMOO LAB</span>#u', $html, $nome);
        }

        $this->assertStringContainsString('Linha do Tempo MIMOO 🐮', $this->html($this->todosOsEmails()['enviado']));
    }

    public function test_textos_ao_cliente_sem_extrusao_nem_engenheiros(): void
    {
        foreach ($this->todosOsEmails() as $nome => $mail) {
            $html = $this->html($mail);

            $this->assertDoesNotMatchRegularExpression('#extrus|engenheir#iu', $html, $nome);
        }

        $emails = $this->todosOsEmails();
        $confirmado = $this->html($emails['pedido confirmado']);
        $this->assertStringContainsString('Nossa equipe já está separando seu pedido!', $confirmado);
        $this->assertStringContainsString('Tempo aproximado de impressão do seu pedido:', $confirmado);
        $this->assertStringContainsString('Impressão 3D em Andamento', $this->html($emails['impressão iniciada']));
    }
}
