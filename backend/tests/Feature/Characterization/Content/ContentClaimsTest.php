<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Content;

use Normalizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * M-59: trava de conteúdo. Alegação ambiental ou de saúde só com comprovação documentada e na forma precisa.
 * Reprova, em tudo o que o cliente vê (loja, index.html, API, e-mails, seeders e factories):
 * "biodegradável" sem "em compostagem industrial", "100%" junto de alegação ambiental, "energia limpa/renovável",
 * "atóxico", "ecológico", "sustentável", "plástico jogado fora"/"desperdício de plástico" e a origem específica
 * do PLA (amido, milho, cana-de-açúcar), que depende do fornecedor.
 *
 * Os arquivos podem ter acentos decompostos (NFD); o texto é normalizado e comparado sem acento.
 */
#[Group('characterization')]
class ContentClaimsTest extends TestCase
{
    /** Regra => expressão regular aplicada ao texto em minúsculas e sem acento. */
    private const FORBIDDEN = [
        'biodegradável sem "em compostagem industrial"' => '/biodegrad\w*+(?!\s+em\s+compostagem\s+industrial)/u',
        '"100%" junto de alegação ambiental' => '/100\s*%[^.\n]{0,40}?(biodegrad|compost|energia|renovav|vegetal|ecologic|sustentav)/u',
        'energia limpa/renovável/verde' => '/energia\s+(limpa|renovav\w*|verde)/u',
        'atóxico' => '/atoxic/u',
        'ecológico' => '/ecologic/u',
        'sustentável' => '/sustentav/u',
        'plástico jogado fora / desperdício de plástico' => '/plastico\s+jogado\s+fora|desperdicio\s+de\s+plastico/u',
        'origem específica do PLA (amido, milho, cana)' => '/\bamido\b|\bmilho\b|cana-de-acucar|\bcana\b/u',
    ];

    private const TEXT_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'css', 'html', 'php', 'json', 'md'];

    public function test_nenhum_texto_publico_tem_alegacao_proibida(): void
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

        $this->assertGreaterThan(50, $checked, 'O teste não achou os arquivos da loja e da API.');
        $this->assertSame(
            [],
            $violations,
            'Alegação ambiental ou de saúde sem comprovação (regra de conteúdo do CLAUDE.md, M-59): '.implode(', ', $violations)
        );
    }

    public function test_o_teste_reprova_os_padroes_proibidos(): void
    {
        $forbidden = [
            '🌱 100% PLA Biodegradável',
            'filamento ecológico',
            'PLA Premium (Atóxico e Biodegradável)',
            '100% de energia limpa',
            'filamento produzido com energia renovável',
            'Feito com amor e PLA sustentável.',
            'Zero estoques acumulados e plástico jogado fora.',
            'Isso garante zero desperdício de plástico',
            'Polímero de origem vegetal (amido de milho).',
            'Filamento PLA de milho & cana',
            // acentos decompostos (NFD), como em alguns arquivos do front
            Normalizer::normalize('ecológico', Normalizer::FORM_D),
            Normalizer::normalize('PLA biodegradável', Normalizer::FORM_D),
        ];

        foreach ($forbidden as $text) {
            $this->assertNotSame([], $this->rulesBrokenBy($text), "Deveria reprovar: {$text}");
        }
    }

    public function test_o_teste_aceita_a_redacao_precisa(): void
    {
        $allowed = [
            'PLA Premium (Biodegradável em Compostagem Industrial)',
            'feitos sob encomenda em PLA, biodegradável em compostagem industrial, com amor',
            '🌱 PLA de Origem Vegetal',
            'Compostável na Indústria',
            'Polímero de origem vegetal, biodegradável em compostagem industrial. Ideal para decoração.',
            'Nada de estoque parado.',
            '100% Personalizável',
            Normalizer::normalize('PLA biodegradável em compostagem industrial', Normalizer::FORM_D),
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
        $backend = dirname(__DIR__, 4);
        $root = dirname($backend);

        $files = [];
        foreach (['src', 'backend/app', 'backend/resources/views', 'backend/database/seeders', 'backend/database/factories'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (in_array(strtolower($file->getExtension()), self::TEXT_EXTENSIONS, true)) {
                    $files[substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1)] = $file->getPathname();
                }
            }
        }

        foreach (array_merge(["{$root}/index.html"], glob("{$root}/public/*.html") ?: []) as $path) {
            $files[substr($path, strlen($root) + 1)] = $path;
        }

        ksort($files);

        return $files;
    }
}
