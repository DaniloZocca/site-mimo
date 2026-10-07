<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Teste de fumaça do deploy: falha (código 1) se a configuração carregada não é segura para produção.
 * Rode no servidor depois de `php artisan config:cache`. Não imprime valores de segredos.
 */
class CheckProductionConfigCommand extends Command
{
    protected $signature = 'mimoo:check-production';

    protected $description = 'Falha se a configuração não estiver pronta para produção (APP_ENV, APP_DEBUG, CORS, cookie de sessão, HTTPS).';

    public function handle(): int
    {
        $problems = [];

        if (! app()->isProduction()) {
            $problems[] = 'APP_ENV não é "production".';
        }

        if (config('app.debug') === true) {
            $problems[] = 'APP_DEBUG está ligado: erros mostrariam código e configuração ao visitante. Use APP_DEBUG=false.';
        }

        $origins = (array) config('cors.allowed_origins', []);
        if (in_array('*', $origins, true) || in_array('*', (array) config('cors.allowed_origins_patterns', []), true)) {
            $problems[] = 'CORS aceita qualquer origem (*). Defina CORS_ALLOWED_ORIGINS com o domínio do frontend.';
        } elseif ($origins === [] || array_filter($origins, fn ($origin) => ! str_starts_with((string) $origin, 'https://')) !== []) {
            $problems[] = 'CORS tem origem sem https:// (ex.: localhost de desenvolvimento). Defina CORS_ALLOWED_ORIGINS com o domínio do frontend.';
        }

        if (config('session.secure') !== true) {
            $problems[] = 'Cookie de sessão sem Secure. Use SESSION_SECURE_COOKIE=true.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL não começa com https://.';
        }

        if ($problems !== []) {
            $this->error('Configuração NÃO está pronta para produção:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->info('Configuração de produção ok.');

        return self::SUCCESS;
    }
}
