<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Content;

use Normalizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * M-71: trava de prova social inventada na loja. A loja ainda não tem histórico público de clientes, avaliações
 * nem status ao vivo das impressoras; números, notas, depoimentos e leituras de máquina que não vêm de dado real
 * podem ser vistos como propaganda enganosa (art. 37 do CDC).
 * Reprova, em tudo o que o cliente vê na loja (src/, index.html e public/*.html): contagem de clientes ou peças
 * ("+1.400", "mais de 1.400 clientes"), nota de avaliação ("4.9/5.0"), depoimento com "Comprou:", "clientes felizes",
 * "Impressora #05", "Fazenda 3D Operando/Imprimindo", temperatura do bico e contador de camadas.
 *
 * Os arquivos podem ter acentos decompostos (NFD); o texto é normalizado e comparado sem acento.
 */
#[Group('characterization')]
class SocialProofClaimsTest extends TestCase
{
    /** Regra => expressão regular aplicada ao texto em minúsculas e sem acento. */
    private const FORBIDDEN = [
        'contagem de clientes ou peças' => '/\+\s*\d{1,3}(?:[.\s]\d{3})+\b|(?:mais\s+de|amados\s+por)\s+[\d.]+\s+(?:clientes|mimos|pecas|itens|pedidos)|\b\d{1,3}(?:\.\d{3})+\s+(?:clientes|mimos|pecas|itens|pedidos)\b/u',
        'nota de avaliação' => '/\b\d[.,]\d\s*\/\s*5(?:[.,]0)?\b|\bde\s+avaliacao\b/u',
        'depoimento ("Comprou:")' => '/\bcomprou:/u',
        'clientes felizes' => '/clientes\s+felizes/u',
        'impressora numerada ao vivo' => '/impressora\s*#\s*\d/u',
        'fazenda 3D ao vivo' => '/fazenda\s+3d\s+(?:operando|imprimindo)/u',
        'temperatura do bico' => '/bico\s*:?\s*(?:a\s+)?\d{3}\s*°?\s*c/u',
        'contador de camadas' => '/camada\s+\d+\s*\/\s*\d+/u',
    ];

    private const TEXT_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'css', 'html', 'json', 'md'];

    public function test_nenhum_texto_da_loja_tem_prova_social_inventada(): void
    {
        $violations = [];
        $checked = 0;

        foreach ($this->scannedFiles() as $relative => $path) {
            $checked++;
            foreach (preg_split('/\R/', (string) file_get_contents($path)) as $index => $line) {
                foreach ($this->rulesBrokenBy($line) as $rule) {
                    $violations[] = "{$relative}:".($index + 1)." ({$rule})";
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'O teste não achou os arquivos da loja.');
        $this->assertSame([], $violations, 'Prova social sem dado real na loja (M-71): '.implode(', ', $violations));
    }

    public function test_o_teste_reprova_os_padroes_proibidos(): void
    {
        $forbidden = [
            'Amados por Mais de 1.400 Clientes',
            '<span><strong>+1.400</strong> mimos impressos e entregues</span>',
            '+1.400 itens impressos e entregues',
            '⭐ 4.9/5.0 de avaliação',
            'Nota 4,9/5',
            '<span class="text-[10px]">Comprou: Vasinho Mimoo Vaquinha</span>',
            '🚀 Envio expresso realizado para 14 clientes felizes esta tarde!',
            '🖨️ Impressora #05 imprimindo Dragão Articulado',
            'Fazenda 3D Operando',
            '<span>Fazenda 3D Imprimindo</span>',
            '<span>Bico: 210°C</span>',
            'Mesa a 60°C • Bico a 210°C',
            'Camada 748/1100',
            Normalizer::normalize('⭐ 4.9/5.0 de avaliação', Normalizer::FORM_D),
        ];

        foreach ($forbidden as $text) {
            $this->assertNotSame([], $this->rulesBrokenBy($text), "Deveria reprovar: {$text}");
        }
    }

    public function test_o_teste_aceita_textos_verdadeiros(): void
    {
        $allowed = [
            'Peças impressas 3D em PLA • Frete calculado pelo CEP no carrinho',
            '🐮 Cada peça é impressa sob encomenda, só depois do pagamento confirmado',
            'Impressão na Fazenda 3D',
            'Fazenda 3D Mimoo:',
            '~{hours}h de impressão ({days})',
            'Etapa {currentIndex + 1} de 6',
            'max-w-[1400px] mx-auto',
            'Parcelamento em até 6x',
            "printerName: 'Impressora MIMOO Prusa #03 (Bico 0.4mm)'",
        ];

        foreach ($allowed as $text) {
            $this->assertSame([], $this->rulesBrokenBy($text), "Não deveria reprovar: {$text}");
        }
    }

    /** @return list<string> */
    private function rulesBrokenBy(string $line): array
    {
        $decomposed = (string) Normalizer::normalize($line, Normalizer::FORM_D);
        $plain = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $decomposed));

        $broken = [];
        foreach (self::FORBIDDEN as $rule => $pattern) {
            if (preg_match($pattern, $plain) === 1) {
                $broken[] = $rule;
            }
        }

        return $broken;
    }

    /** @return array<string, string> caminho relativo à raiz do repositório => caminho absoluto */
    private function scannedFiles(): array
    {
        $root = dirname(__DIR__, 5);

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src", RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (in_array(strtolower($file->getExtension()), self::TEXT_EXTENSIONS, true)) {
                $files[substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1)] = $file->getPathname();
            }
        }

        foreach (array_merge(["{$root}/index.html"], glob("{$root}/public/*.html") ?: []) as $path) {
            $files[substr($path, strlen($root) + 1)] = $path;
        }

        ksort($files);

        return $files;
    }
}
