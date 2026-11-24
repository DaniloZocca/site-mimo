<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Vaquinha Mimoo do logo no cabeçalho dos e-mails (M-70), no lugar do emoji 🐮.
 *
 * O e-mail é lido fora da loja, então a imagem precisa de URL absoluta e pública: o arquivo fica em
 * backend/public (servido pela própria API, APP_URL/images/email/...), cópia reduzida do
 * public/assets/logo-mascot.png da loja. APP_URL que não seja http(s) devolve null e o e-mail
 * mantém o emoji, em vez de levar uma imagem quebrada.
 */
final class EmailBrandImage
{
    public const MASCOT_PATH = 'images/email/vaquinha-mimoo.png';

    public static function mascotUrl(): ?string
    {
        $base = rtrim(trim((string) config('app.url')), '/');

        if (preg_match('#^https?://[^/\s]+#i', $base) !== 1) {
            return null;
        }

        return $base.'/'.self::MASCOT_PATH;
    }
}
