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
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-74: o rodapé de todos os e-mails mostra o WhatsApp real da loja e o horário de atendimento, no lugar
 * do número de exemplo "(11) 99999-9999". Os e-mails de pedido não usam termos técnicos para o cliente.
 */
#[Group('characterization')]
class EmailStoreContactTest extends CharacterizationTestCase
{
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

    public function test_todos_os_emails_mostram_o_whatsapp_real_e_o_horario(): void
    {
        foreach ($this->todosOsEmails() as $nome => $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('href="https://wa.me/5519996317677"', $html, $nome);
            $this->assertStringContainsString('(19) 99631-7677', $html, $nome);
            $this->assertStringContainsString('de segunda a sexta, das 8h às 17h', $html, $nome);
            $this->assertStringNotContainsString('99999-9999', $html, $nome);
            $this->assertStringNotContainsString('5511999999999', $html, $nome);
        }
    }

    public function test_emails_de_pedido_sem_termos_tecnicos(): void
    {
        foreach ($this->todosOsEmails() as $nome => $mail) {
            $html = $mail->render();

            foreach (['criptográfica', 'Bico aquecido', '210°C', 'Mesa a', '0.16mm'] as $termo) {
                $this->assertStringNotContainsString($termo, $html, "{$nome}: {$termo}");
            }
        }
    }
}
