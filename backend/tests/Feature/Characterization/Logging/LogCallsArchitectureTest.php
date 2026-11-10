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
 *
 * M-54: também reprova a variável da exceção (do catch ou de um parâmetro Throwable/Exception/Error) em
 * qualquer lugar da chamada, a não ser $e::class ou $e->método() (ex.: getCode()); (string) $e, interpolação,
 * getTraceAsString(), __toString() e getPrevious(); e os helpers logger() e info(). report($e) continua
 * permitido: passa pelo callback seguro do bootstrap/app.php.
 */
#[Group('characterization')]
class LogCallsArchitectureTest extends TestCase
{
    private const ALLOWED = ['Support/ErrorReference.php'];

    /** Funções que recebem a exceção e devolvem só dados seguros (contexto higienizado, nome de classe). */
    private const SAFE_CALLEES = ['ErrorReference::context', 'ErrorReference::for', 'ErrorReference::existing', 'self::failingClass'];

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

            $code = (string) file_get_contents($file->getPathname());
            $checked += count($this->logStatements($code));
            foreach ($this->violations($code) as $line) {
                $violations[] = "app/{$relative}:{$line}";
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

        $this->assertSame([2, 3, 4], $this->violations($code));
    }

    public function test_o_teste_reprova_a_excecao_em_outra_chave_cast_interpolacao_e_helpers(): void
    {
        $code = <<<'PHP'
        <?php
        try { x(); } catch (\RuntimeException|QueryException $falha) {
            Log::error('a', ['erro' => $falha]);
            Log::error('b', ['texto' => (string) $falha]);
            Log::error("c {$falha}");
            Log::error('d', ['t' => $falha->getTraceAsString()]);
            info('e', ['erro' => $falha]);
            logger('f', ['anterior' => $falha->getPrevious()]);
            Log::error('ok', ['classe' => $falha::class, 'codigo' => $falha->getCode()]);
            report($falha);
            info('ok', ['pedido' => $pedido]);
            Log::error('ok', [...ErrorReference::context($falha), 'tipo' => $falha instanceof QueryException]);
            Log::error('h', ['x' => json_encode($falha)]);
        }
        function failed(Evento $evento, Throwable $exception): void {
            Log::error('g', ['detalhe' => $exception]);
            Log::error('ok', ['pedido' => $evento->order->order_number, 'exception' => $exception::class]);
        }
        PHP;

        $this->assertSame([3, 4, 5, 6, 7, 8, 13, 16], $this->violations($code));
    }

    public function test_sanitizador_cobre_cpf_e_cnpj_formatados(): void
    {
        $this->assertSame(
            'cpf [x] cnpj [x] cep [x] linha 42',
            ErrorReference::sanitizeMessage('cpf 529.982.247-25 cnpj 12.345.678/0001-90 cep 01310-930 linha 42')
        );
    }

    /**
     * Linhas das chamadas de log que gravam a mensagem, o trace ou o objeto da exceção.
     *
     * @return list<int>
     */
    private function violations(string $code): array
    {
        $thrown = $this->throwableVariables($code);
        $lines = [];

        foreach ($this->logStatements($code) as [$line, $statement]) {
            if (str_contains($statement, 'getMessage(')
                || str_contains($statement, 'getTraceAsString(')
                || str_contains($statement, '__toString(')
                || str_contains($statement, 'getPrevious(')
                || preg_match('/[\'"]exception[\'"]\s*=>\s*\$\w+\s*[,\]\)]/', $statement) === 1
                || $this->usesThrowableAsValue($statement, $thrown)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Nomes das variáveis que guardam uma exceção: a do catch e os parâmetros com tipo *Throwable, *Exception ou
     * *Error (o último tipo antes da variável, também em união: catch (A|B $e)).
     *
     * @return list<string>
     */
    private function throwableVariables(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $names = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE || $i === 0) {
                continue;
            }

            $type = $tokens[$i - 1];
            if (is_array($type) && in_array($type[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(Throwable|Exception|Error)$/', $type[1]) === 1) {
                $names[] = $token[1];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * A variável da exceção usada como valor (não seguida de :: ou ->): em array, cast, concatenação ou interpolação.
     *
     * @param  list<string>  $thrown
     */
    private function usesThrowableAsValue(string $statement, array $thrown): bool
    {
        $tokens = array_values(array_filter(
            token_get_all('<?php '.$statement),
            fn ($t): bool => ! is_array($t) || $t[0] !== T_WHITESPACE
        ));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE || ! in_array($token[1], $thrown, true)) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $next = is_array($next) ? $next[1] : $next;
            if (in_array($next, ['::', '->', '?->', 'instanceof'], true) || $this->isSafeCallArgument($tokens, $i)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * A variável é o primeiro argumento de uma função de SAFE_CALLEES (ex.: ErrorReference::context($e, $ref)).
     *
     * @param  list<mixed>  $tokens  sem espaços
     */
    private function isSafeCallArgument(array $tokens, int $i): bool
    {
        $piece = fn (int $k): ?string => isset($tokens[$k]) ? (is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k]) : null;

        if ($piece($i - 1) !== '(' || $piece($i - 3) !== '::') {
            return false;
        }

        return in_array($piece($i - 4).'::'.$piece($i - 2), self::SAFE_CALLEES, true);
    }

    /**
     * Cada chamada de log (Log::..., logger(...), info(...)) até o ";" final, com a linha onde começa.
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
            $previous = $this->previousSignificant($tokens, $i);
            $isLog = ($name === 'Log' || $name === 'Illuminate\Support\Facades\Log') && $next === '::'
                || in_array($name, ['logger', 'info'], true) && $next === '('
                    && ! in_array($previous, ['->', '?->', '::', 'function', 'new'], true);
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
    private function previousSignificant(array $tokens, int $i): ?string
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                continue;
            }

            return is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
        }

        return null;
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
