<?php

use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Support\ErrorReference;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
        $middleware->preventRequestForgery(except: [
            'api/webhooks/mercadopago',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Conta de cliente (M-26): erro sempre em JSON (sem redirecionar para uma tela de login que não existe).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/conta/*') || $request->expectsJson()
        );

        // M-47: erro não tratado (rota, fila, painel, console) vai para o log só com o contexto seguro do
        // ErrorReference. O log padrão do Laravel gravaria a mensagem (o SQL com e-mail/CPF de uma
        // QueryException) e o trace; o stop() impede. A lista "não reportar" (validação, 404...) segue igual.
        $exceptions->report(function (Throwable $e): void {
            Log::error('Erro não tratado.', [
                ...ErrorReference::context($e, ErrorReference::for($e)),
                'route' => ErrorReference::route(),
            ]);
        })->stop();

        // M-47: o 500 em JSON leva o mesmo código do log, para o cliente passar ao suporte. Só acrescenta
        // o campo: o "message" e o status continuam os do Laravel.
        $exceptions->respond(function (Response $response, Throwable $e): Response {
            $ref = ErrorReference::existing($e);

            if ($ref !== null && $response instanceof JsonResponse && $response->getStatusCode() === 500) {
                $data = $response->getData(true);
                if (is_array($data)) {
                    $response->setData([...$data, 'error_ref' => $ref]);
                }
            }

            return $response;
        });
    })->create();
