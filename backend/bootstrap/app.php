<?php

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // Isenção explícita de CSRF para o Webhook do Mercado Pago
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/mercadopago',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
