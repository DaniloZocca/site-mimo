<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Tracking;

use App\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CapturesLogs;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Resistência a enumeração e rate limit do rastreio.
 */
#[Group('characterization')]
class TrackingEnumerationAndRateLimitTest extends CharacterizationTestCase
{
    use CapturesLogs;

    protected function setUp(): void
    {
        parent::setUp();
        Order::factory()->create(['order_number' => 'MIMOO-ABC12345', 'customer_cpf' => '52998224725', 'customer_email' => 'fernanda@example.com']);
    }

    public function test_cpf_errado_e_pedido_inexistente_tem_respostas_identicas(): void
    {
        $wrongCpf = $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '11111111111']);
        $noSuchOne = $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ZZZ99999', 'cpf_prefix' => '11111111111']);

        $this->assertSame(404, $wrongCpf->getStatusCode());
        $this->assertSame($wrongCpf->getStatusCode(), $noSuchOne->getStatusCode());
        $this->assertSame($wrongCpf->getContent(), $noSuchOne->getContent());
        $this->assertSame('{"message":"Nenhuma encomenda localizada com a combina\\u00e7\\u00e3o de dados informada."}', $wrongCpf->getContent());
        $this->assertSame($wrongCpf->headers->get('Content-Type'), $noSuchOne->headers->get('Content-Type'));
    }

    public function test_email_errado_e_pedido_inexistente_tem_respostas_identicas(): void
    {
        $wrong = $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ABC12345', 'email' => 'x@y.com']);
        $none = $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ZZZ99999', 'email' => 'x@y.com']);

        $this->assertSame([404, $wrong->getContent()], [$none->getStatusCode(), $none->getContent()]);
    }

    public function test_diferenca_so_no_log_do_servidor(): void
    {
        $this->captureLogs();

        $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '11111111111']);
        $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ZZZ99999', 'cpf_prefix' => '11111111111']);

        // Só o pedido existente gera o warning "Tentativa de enumeração IDOR bloqueada".
        $warnings = $this->logsAtLevels(['warning'], 'Tentativa de enumeração IDOR bloqueada');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('MIMOO-ABC12345', $warnings[0]->message);
    }

    public function test_decima_primeira_consulta_no_mesmo_minuto_retorna_429(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ZZZ99999', 'cpf_prefix' => sprintf('%011d', $i)])->assertNotFound();
        }

        $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-ABC12345', 'cpf_prefix' => '529'])
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '10');
    }
}
