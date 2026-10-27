<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPages;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-41, item 7: o SQL do runbook de exclusão de conta apaga cadastro, token de nova senha e sessões
 * só da conta pedida, e mantém os pedidos com o mesmo e-mail.
 */
class ExcluirContaClienteTest extends CharacterizationTestCase
{
    public function test_sql_do_runbook_apaga_a_conta_os_tokens_e_as_sessoes_e_mantem_os_pedidos(): void
    {
        $ana = Customer::factory()->create(['email' => 'ana@exemplo.com']);
        $bia = Customer::factory()->create(['email' => 'bia@exemplo.com']);

        foreach (['ana@exemplo.com', 'bia@exemplo.com'] as $email) {
            DB::table('customer_password_reset_tokens')->insert(['email' => $email, 'token' => Str::random(60), 'created_at' => now()]);
        }
        $this->insertSession('sessao-ana-1', (string) $ana->id);
        $this->insertSession('sessao-ana-2', (string) $ana->id);
        $this->insertSession('sessao-bia', (string) $bia->id);
        $this->insertSession('sessao-admin', '1');
        $this->insertSession('sessao-visitante', null);

        $pedidoDaAna = Order::factory()->create(['customer_email' => 'Ana@Exemplo.com']);
        $pedidoDaBia = Order::factory()->create(['customer_email' => 'bia@exemplo.com']);

        // O e-mail colado com maiúsculas e espaços, como pode vir do e-mail do cliente.
        $this->runRunbookSql(' Ana@Exemplo.COM ');

        $this->assertDatabaseMissing('customers', ['id' => $ana->id]);
        $this->assertDatabaseMissing('customer_password_reset_tokens', ['email' => 'ana@exemplo.com']);
        $this->assertSame(0, DB::table('sessions')->whereIn('id', ['sessao-ana-1', 'sessao-ana-2'])->count());

        $this->assertDatabaseHas('customers', ['id' => $bia->id]);
        $this->assertDatabaseHas('customer_password_reset_tokens', ['email' => 'bia@exemplo.com']);
        $this->assertSame(
            ['sessao-admin', 'sessao-bia', 'sessao-visitante'],
            DB::table('sessions')->orderBy('id')->pluck('id')->all(),
        );

        $this->assertDatabaseHas('orders', ['id' => $pedidoDaAna->id, 'customer_email' => 'Ana@Exemplo.com']);
        $this->assertDatabaseHas('orders', ['id' => $pedidoDaBia->id]);
    }

    public function test_sql_do_runbook_sem_conta_com_o_email_nao_apaga_nada(): void
    {
        $bia = Customer::factory()->create(['email' => 'bia@exemplo.com']);
        $this->insertSession('sessao-bia', (string) $bia->id);
        Order::factory()->create(['customer_email' => 'ana@exemplo.com']);

        $this->runRunbookSql('ana@exemplo.com');

        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertSame(1, DB::table('orders')->count());
    }

    /**
     * Roda o arquivo como o operador roda (troca EMAIL_DA_CONTA), sem o BEGIN/COMMIT: o teste já está
     * dentro de uma transação. A conferência final do arquivo precisa dar 0.
     */
    private function runRunbookSql(string $email): void
    {
        $sql = (string) file_get_contents(base_path('../docs/consultas/m-41-excluir-conta-cliente.sql'));
        $sql = str_replace('EMAIL_DA_CONTA', str_replace("'", "''", $email), $sql);

        $statements = collect(explode(';', (string) preg_replace('/^--.*$/m', '', $sql)))
            ->map(fn (string $statement): string => trim($statement))
            ->reject(fn (string $statement): bool => in_array(strtoupper($statement), ['', 'BEGIN', 'COMMIT'], true))
            ->values();

        $this->assertCount(4, $statements, 'O arquivo deve ter 3 DELETE e a conferência final.');

        foreach ($statements->slice(0, 3) as $statement) {
            DB::delete($statement);
        }

        $this->assertSame(0, (int) DB::selectOne($statements[3])->contas_restantes);
    }

    private function insertSession(string $id, ?string $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }
}
