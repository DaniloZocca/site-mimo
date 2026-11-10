<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Logging;

use App\Support\ErrorReference;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * M-47: trava para o futuro. Nenhuma chamada de log em app/ grava a mensagem da exceção (getMessage()) ou o
 * objeto da exceção ('exception' => $e, que o Monolog escreve com mensagem e trace). O único lugar que lê
 * a mensagem é o ErrorReference, que a higieniza e só para erros do PHP.
 */
#[Group('characterization')]
class LogCallsArchitectureTest extends TestCase
{
    private const ALLOWED = ['Support/ErrorReference.php'];

    public function test_nenhum_log_em_app_grava_mensagem_ou_objeto_da_excecao(): void
    {
        $appDir = dirname(__DIR__, 4).'/app';
        $violations = [];
        $checked = 0;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
            if ($file->getExtension() !== 'php' || in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            foreach ($this->logStatements((string) file_get_contents($file->getPathname())) as [$line, $statement]) {
                $checked++;
                if (str_contains($statement, 'getMessage(')
                    || str_contains($statement, 'getTraceAsString(')
                    || preg_match('/[\'"]exception[\'"]\s*=>\s*\$\w+\s*[,\]\)]/', $statement) === 1) {
                    $violations[] = "app/{$relative}:{$line}";
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'O teste não achou as chamadas de log de app/.');
        $this->assertSame([], $violations, 'Log com a mensagem ou o objeto da exceção (use ErrorReference::context): '.implode(', ', $violations));
    }

    public function test_o_teste_reprova_os_padroes_proibidos(): void
    {
        $code = <<<'PHP'
        <?php
        Log::error('a '.$e->getMessage());
        Log::warning('b', ['exception' => $e]);
        logger()->error('c', ['order' => 1, 'exception' => $e, 'x' => 2]);
        Log::error('ok', ['exception' => $e::class]);
        PHP;

        $flagged = array_filter(
            $this->logStatements($code),
            fn (array $s): bool => str_contains($s[1], 'getMessage(')
                || preg_match('/[\'"]exception[\'"]\s*=>\s*\$\w+\s*[,\]\)]/', $s[1]) === 1
        );

        $this->assertSame([2, 3, 4], array_column(array_values($flagged), 0));
    }

    public function test_sanitizador_cobre_cpf_e_cnpj_formatados(): void
    {
        $this->assertSame(
            'cpf [x] cnpj [x] cep [x] linha 42',
            ErrorReference::sanitizeMessage('cpf 529.982.247-25 cnpj 12.345.678/0001-90 cep 01310-930 linha 42')
        );
    }

    /**
     * Cada chamada de log (Log::..., logger(...), ->log(...)) até o ";" final, com a linha onde começa.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function logStatements(string $code): array
    {
        $tokens = token_get_all($code);
        $statements = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($token[1], '\\');
            $next = $this->nextSignificant($tokens, $i);
            $isLog = ($name === 'Log' || $name === 'Illuminate\Support\Facades\Log') && $next === '::'
                || $name === 'logger' && $next === '(';
            if (! $isLog) {
                continue;
            }

            $text = '';
            $depth = 0;
            for ($j = $i; $j < $count; $j++) {
                $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $text .= $piece;
                if (in_array($piece, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($piece, [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($piece === ';' && $depth <= 0) {
                    break;
                }
            }

            $statements[] = [$token[2], $text];
            $i = $j;
        }

        return $statements;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function nextSignificant(array $tokens, int $i): ?string
    {
        for ($k = $i + 1; $k < count($tokens); $k++) {
            if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                continue;
            }

            return is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
        }

        return null;
    }
}
