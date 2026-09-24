<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Injeta cabeçalhos HTTP defensivos (SecOps / Hardening).
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // 1. Anti-Clickjacking: Permite renderização de frames da mesma origem (necessário para componentes do Filament/Livewire)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // 2. Anti-MIME Sniffing: Força o navegador a respeitar o Content-Type declarado
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // 3. Controle de Referenciador: Preserva a privacidade na navegação cross-origin
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 4. Bloqueio de APIs invasivas do dispositivo (Hardware Restrictions)
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // 5. HSTS (HTTP Strict Transport Security): Força conexões HTTPS por 1 ano
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // 6. CSP (Content Security Policy): Suporte total a FilePond Canvas (blob: e data:), Web Workers e Mercado Pago
        $cspPolicy = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://sdk.mercadopago.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https:",
            "media-src 'self' data: blob:",
            "worker-src 'self' blob:",
            "connect-src 'self' https://api.mercadopago.com https://events.mercadopago.com blob: data:",
            "frame-src 'self' https://www.mercadopago.com.br https://sdk.mercadopago.com",
            "object-src 'none'",
            "base-uri 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $cspPolicy);

        // 7. Remove cabeçalhos de identificação de servidor para evitar Server Fingerprinting
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
