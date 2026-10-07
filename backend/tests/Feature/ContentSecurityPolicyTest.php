<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    private function scriptSrc(string $csp): string
    {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);

            if (str_starts_with($directive, 'script-src ')) {
                return $directive;
            }
        }

        $this->fail('CSP sem script-src: '.$csp);
    }

    public function test_api_responde_sem_unsafe_inline_nem_unsafe_eval(): void
    {
        $response = $this->postJson('/api/shipping/calculate', []);

        $scriptSrc = $this->scriptSrc((string) $response->headers->get('Content-Security-Policy'));

        $this->assertSame("script-src 'self' https://sdk.mercadopago.com", $scriptSrc);
    }

    public function test_health_check_responde_sem_unsafe_inline_nem_unsafe_eval(): void
    {
        $response = $this->get('/up');

        $scriptSrc = $this->scriptSrc((string) $response->headers->get('Content-Security-Policy'));

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
    }

    public function test_painel_filament_mantem_o_que_o_filament_exige(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $scriptSrc = $this->scriptSrc((string) $response->headers->get('Content-Security-Policy'));

        $this->assertStringContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringContainsString("'unsafe-eval'", $scriptSrc);
    }

    public function test_demais_diretivas_iguais_nos_dois_contextos(): void
    {
        $api = (string) $this->get('/up')->headers->get('Content-Security-Policy');
        $painel = (string) $this->get('/admin/login')->headers->get('Content-Security-Policy');

        $semScriptSrc = fn (string $csp) => preg_replace('/script-src [^;]*/', '', $csp);

        $this->assertSame($semScriptSrc($painel), $semScriptSrc($api));
        $this->assertStringContainsString("object-src 'none'", $api);
    }
}
