<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * M-45: configuração comum ao processo do teste e aos processos auxiliares (bin/worker.php). Nada aqui é segredo:
 * token e segredo do webhook são de mentira, e nenhuma chamada sai para a internet (Mercado Pago pelo cliente
 * falso, frete pela tabela de reserva).
 */
final class Harness
{
    public const ACCESS_TOKEN = 'TEST-concorrencia-access-token';

    public const WEBHOOK_SECRET = 'segredo-de-teste-da-concorrencia';

    public static function configure(Repository $config): void
    {
        $config->set('services.mercadopago.access_token', self::ACCESS_TOKEN);
        $config->set('services.mercadopago.public_key', 'APP_USR-public-concorrencia');
        $config->set('services.mercadopago.webhook_secret', self::WEBHOOK_SECRET);
        $config->set('services.shipping.melhor_envio.token', '');
        $config->set('app.url', 'http://localhost');
        $config->set('cache.default', 'array');
        // Fila de verdade no banco: o teste conta os jobs gravados (1 aviso por pagamento aprovado)
        $config->set('queue.default', 'database');
        $config->set('mail.default', 'array');
        $config->set('session.driver', 'array');
        $config->set('logging.default', 'null');
    }

    /**
     * Cabeçalhos assinados do webhook (mesmo HMAC do MercadoPagoWebhookController).
     *
     * @return array<string, string>
     */
    public static function signedWebhookServer(string $dataId, string $requestId): array
    {
        $ts = time();
        $v1 = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", self::WEBHOOK_SECRET);

        return [
            'HTTP_X_SIGNATURE' => "ts={$ts},v1={$v1}",
            'HTTP_X_REQUEST_ID' => $requestId,
        ];
    }
}
