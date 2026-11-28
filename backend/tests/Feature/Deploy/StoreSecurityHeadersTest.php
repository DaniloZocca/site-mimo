<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-86 (achado S7): a loja (front React, publicada como Web App da Hostinger) manda os cabeçalhos de segurança pelo
 * `public/.htaccess` da raiz do repositório, que o Vite copia para `dist/`. O teste lê os arquivos da loja; a prova
 * no servidor de verdade é a conferência do roteiro (docs/deploy-hostinger.md, "Cabeçalhos da loja").
 */
class StoreSecurityHeadersTest extends CharacterizationTestCase
{
    private function repoPath(string $path): string
    {
        return dirname(base_path()).'/'.$path;
    }

    private function htaccess(): string
    {
        $file = $this->repoPath('public/.htaccess');
        $this->assertFileExists($file, 'a loja precisa do public/.htaccess com os cabeçalhos (M-86)');

        return (string) file_get_contents($file);
    }

    /** @return array<string, string> nome do cabeçalho => valor */
    private function headers(): array
    {
        $htaccess = $this->htaccess();
        $this->assertSame(1, preg_match_all('/<IfModule\b/i', $htaccess), 'um bloco <IfModule> só, o dos cabeçalhos');
        $this->assertSame(1, preg_match('/<IfModule mod_headers\.c>(.*?)<\/IfModule>/s', $htaccess, $block));

        preg_match_all('/^[ \t]*Header always set ([A-Za-z-]+) "([^"]*)"[ \t]*$/m', $block[1], $matches, PREG_SET_ORDER);
        // Nenhuma outra diretiva Header (set sem always, append, unset, fora do bloco): o teste não pode ficar verde
        // com uma segunda linha que muda o cabeçalho que ele confere.
        $this->assertSame(count($matches), preg_match_all('/^[ \t]*Header\b/mi', $htaccess), 'só as linhas `Header always set` dentro do bloco');
        $this->assertCount(6, $matches, 'os 6 cabeçalhos, cada um uma vez');
        $headers = [];
        foreach ($matches as [, $name, $value]) {
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /** @return array<string, list<string>> diretiva => fontes */
    private function csp(): array
    {
        $csp = $this->headers()['content-security-policy'] ?? '';
        $this->assertNotSame('', $csp, 'falta a Content-Security-Policy');

        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $directive) {
            $parts = preg_split('/\s+/', $directive);
            $directives[strtolower(array_shift($parts))] = $parts;
        }

        return $directives;
    }

    private function mercadoPagoJs(): string
    {
        return (string) file_get_contents($this->repoPath('src/services/mercadoPago.js'));
    }

    public function test_os_seis_cabecalhos_saem_em_toda_resposta(): void
    {
        $headers = $this->headers();

        foreach (['strict-transport-security', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'content-security-policy'] as $name) {
            $this->assertArrayHasKey($name, $headers, "falta o cabeçalho {$name} (com `Header always set`)");
        }

        $this->assertSame('DENY', $headers['x-frame-options']);
        $this->assertSame('nosniff', $headers['x-content-type-options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['referrer-policy']);
        $this->assertStringContainsString('camera=()', $headers['permissions-policy']);
    }

    public function test_hsts_em_etapas_sem_preload(): void
    {
        $hsts = $this->headers()['strict-transport-security'];

        $this->assertSame(1, preg_match('/^max-age=(\d+)$/', $hsts, $match), 'HSTS só com max-age (sem includeSubDomains/preload nesta etapa)');
        $this->assertGreaterThanOrEqual(86400, (int) $match[1]);
    }

    public function test_csp_nao_deixa_abrir_a_loja_em_quadro_nem_plugin(): void
    {
        $csp = $this->csp();

        $this->assertSame(["'self'"], $csp['default-src'] ?? null);
        $this->assertSame(["'none'"], $csp['frame-ancestors'] ?? null);
        $this->assertSame(["'none'"], $csp['object-src'] ?? null);
        $this->assertSame(["'self'"], $csp['base-uri'] ?? null);
        $this->assertSame(["'self'"], $csp['form-action'] ?? null);
        $this->assertArrayHasKey('upgrade-insecure-requests', $csp);
    }

    public function test_script_so_do_proprio_site_e_do_mercado_pago(): void
    {
        $script = $this->csp()['script-src'] ?? [];

        foreach (["'unsafe-inline'", "'unsafe-eval'", '*', 'data:', 'https:', 'http:', 'blob:'] as $forbidden) {
            $this->assertNotContains($forbidden, $script, "script-src não pode ter {$forbidden}");
        }
        foreach ($script as $source) {
            $this->assertMatchesRegularExpression("/^('self'|'nonce-[A-Za-z0-9+\/_-]+=*'|https:\/\/[a-z0-9.-]+)$/", $source);
            $this->assertStringNotContainsString('*', $source, 'sem curinga em script-src');
        }
        $this->assertContains('https://sdk.mercadopago.com', $script);
    }

    public function test_nonce_aleatorio_e_igual_no_htaccess_e_no_mercado_pago(): void
    {
        $nonces = array_values(array_filter($this->csp()['script-src'] ?? [], fn (string $s): bool => str_starts_with($s, "'nonce-")));
        $this->assertCount(1, $nonces, 'um nonce só na script-src');
        $nonce = substr($nonces[0], strlen("'nonce-"), -1);

        // 128 bits ou mais: 22+ caracteres base64; nunca uma palavra.
        $this->assertGreaterThanOrEqual(22, strlen($nonce));
        $this->assertMatchesRegularExpression('/[0-9]/', $nonce);
        $this->assertMatchesRegularExpression('/[a-z]/', $nonce);
        $this->assertMatchesRegularExpression('/[A-Z]/', $nonce);

        $js = $this->mercadoPagoJs();
        $this->assertSame(1, preg_match("/export const MP_CSP_NONCE = '([^']+)';/", $js, $match), 'MP_CSP_NONCE não encontrado em src/services/mercadoPago.js');
        $this->assertSame($nonce, $match[1], 'o nonce da CSP e o do mercadoPago.js precisam ser iguais');
        $this->assertStringContainsString('deviceProfileCspNonce: MP_CSP_NONCE', $js);
        $this->assertStringContainsString('advancedFraudPrevention: true', $js);
    }

    public function test_conexoes_cobrem_a_api_o_viacep_e_o_mercado_pago(): void
    {
        $csp = $this->csp();

        foreach (['https://api.mimoolab.com.br', 'https://viacep.com.br', 'https://api.mercadopago.com', 'https://secure-fields.mercadopago.com'] as $origin) {
            $this->assertContains($origin, $csp['connect-src'] ?? [], "connect-src sem {$origin}");
        }
        $this->assertContains('https://secure-fields.mercadopago.com', $csp['frame-src'] ?? []);
        $this->assertContains('data:', $csp['img-src'] ?? [], 'QR Code do PIX é data:');
        $this->assertContains('https://api.mimoolab.com.br', $csp['img-src'] ?? [], 'fotos dos produtos vêm da API');
        $this->assertContains('https://fonts.googleapis.com', $csp['style-src'] ?? []);
        $this->assertContains('https://fonts.gstatic.com', $csp['font-src'] ?? []);

        // O confete (canvas-confetti) roda num worker de blob; sem worker-src o navegador usa a script-src e bloqueia
        // sem erro (o confete some). Achado na conferência no ar da M-86.
        $this->assertSame(["'self'", 'blob:'], $csp['worker-src'] ?? null);

        // Todo endereço fixo que o front chama com fetch precisa estar na connect-src.
        $fetchOrigins = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoPath('src'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! preg_match('/\.(js|jsx)$/', $file->getFilename())) {
                continue;
            }
            preg_match_all('/fetch\(\s*[`\'"](https:\/\/[a-z0-9.-]+)/i', (string) file_get_contents($file->getPathname()), $m);
            $fetchOrigins = [...$fetchOrigins, ...$m[1]];
        }
        foreach (array_unique($fetchOrigins) as $origin) {
            $this->assertContains($origin, $csp['connect-src'] ?? [], "o front chama {$origin}, que a connect-src não libera");
        }
    }

    public function test_aviso_do_3ds_no_htaccess(): void
    {
        $this->assertStringContainsString(
            'Se o 3DS do cartão for ligado, frame-src e form-action precisam dos domínios dos bancos (desafio 3DS)',
            $this->htaccess(),
        );
    }

    public function test_zod_sem_jit_antes_do_app(): void
    {
        $main = (string) file_get_contents($this->repoPath('src/main.jsx'));
        $this->assertStringStartsWith("import './config/zod'", $main, 'o z.config({ jitless: true }) precisa vir antes do App (M-86)');
        $this->assertStringContainsString('z.config({ jitless: true })', (string) file_get_contents($this->repoPath('src/config/zod.js')));
    }

    public function test_build_copia_o_htaccess_para_dist(): void
    {
        $dist = $this->repoPath('dist');
        if (! is_file($dist.'/index.html')) {
            $this->markTestSkipped('sem dist/ (rode `npm run build` na raiz para conferir a cópia do .htaccess)');
        }

        $this->assertFileExists($dist.'/.htaccess', 'o Vite não copiou o public/.htaccess para dist/');
        $this->assertFileEquals($this->repoPath('public/.htaccess'), $dist.'/.htaccess', 'dist/.htaccess desatualizado: rode `npm run build` de novo');
    }
}
