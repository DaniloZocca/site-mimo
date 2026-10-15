<?php

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Blindagem Global de Segurança HTTP (HSTS, CSP, Anti-Clickjacking, Anti-Sniffing)
        $middleware->append(SecurityHeadersMiddleware::class);

        // Conta de cliente (M-26): sem sessão responde 401 em JSON, sem redirecionar.
        // As demais rotas mantêm o padrão do Laravel (rota "login").
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/conta/*') ? null : route('login')
        );

        // Isenção explícita de CSRF para o Webhook do Mercado Pago
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/mercadopago',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Conta de cliente (M-26): erro sempre em JSON (sem redirecionar para uma tela de login que não existe).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/conta/*') || $request->expectsJson()
        );
    })->create();
