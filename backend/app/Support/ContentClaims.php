<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

/**
 * Confere texto que o cliente vai ver contra as formas proibidas da M-59 (`config/content_claims.php`).
 */
final class ContentClaims
{
    /**
     * Regras quebradas pelo texto (vazio = texto aceito). Compara em minúsculas e sem acento.
     *
     * @return list<string>
     */
    public static function brokenRules(?string $text): array
    {
        $decomposed = (string) Normalizer::normalize((string) $text, Normalizer::FORM_D);
        $plain = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $decomposed));

        $broken = [];
        foreach ((array) config('content_claims.forbidden', []) as $rule => $pattern) {
            if (preg_match((string) $pattern, $plain) === 1) {
                $broken[] = (string) $rule;
            }
        }

        return $broken;
    }

    public static function message(array $brokenRules): string
    {
        return 'Este texto aparece na loja e não pode ter alegação ambiental ou de saúde sem comprovação: '
            .implode(', ', $brokenRules).'. Para o PLA, use "biodegradável em compostagem industrial".';
    }
}
