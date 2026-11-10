<?php

declare(strict_types=1);

namespace App\Support;

/**
 * M-45: o que o banco de produção (MariaDB 11.8 na Hostinger) precisa ter para se comportar como o Postgres.
 * Usado pela conexão `mariadb` (config/database.php), pela migration de collation e pelo mimoo:check-production.
 */
final class DatabaseSettings
{
    /** Versão mínima do MariaDB (READ COMMITTED + SKIP LOCKED na fila). */
    public const MIN_MARIADB_VERSION = '10.6.0';

    /** Valor de @@transaction_isolation esperado em cada sessão. */
    public const ISOLATION = 'READ-COMMITTED';

    /** Espera máxima por trava, em segundos (o Mercado Pago desiste do webhook em 22 s). */
    public const LOCK_WAIT_TIMEOUT_SECONDS = 10;

    /** Collation exata (maiúscula ≠ minúscula, como o Postgres compara texto). */
    public const BINARY_COLLATION = 'utf8mb4_bin';

    /**
     * Colunas de token, chave e e-mail que comparam exato, como no Postgres.
     * Os UUIDs (`tracking_token`, `idempotency_key`...) usam o tipo UUID nativo do MariaDB 10.7+ e ficam fora.
     *
     * @var array<string, list<string>>
     */
    public const BINARY_COLUMNS = [
        'users' => ['email'],
        'password_reset_tokens' => ['email'],
        'sessions' => ['id'],
        'cache' => ['key'],
        'cache_locks' => ['key'],
        'customers' => ['email'],
        'customer_password_reset_tokens' => ['email'],
        'orders' => ['order_number', 'customer_email', 'request_fingerprint'],
        'order_payments' => ['external_id'],
        'production_logs' => ['code', 'reference'],
    ];
}
