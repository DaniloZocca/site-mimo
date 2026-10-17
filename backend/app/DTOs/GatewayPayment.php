<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Support\Cents;

/**
 * Pagamento do Mercado Pago normalizado, vindo da consulta (GET /v1/payments/{id}) ou da
 * criação (POST /v1/payments). Os dois caminhos de aprovação validam este mesmo objeto.
 */
readonly class GatewayPayment
{
    public function __construct(
        public string $id,
        public string $status,
        public ?int $amountCents,
        public ?string $currencyId,
        public ?string $externalReference,
        public ?bool $liveMode,
    ) {}

    /**
     * @param  array<string, mixed>  $data  campos id, status, transaction_amount, currency_id, external_reference, live_mode
     */
    public static function fromMercadoPago(array $data): self
    {
        $currency = $data['currency_id'] ?? null;
        $reference = $data['external_reference'] ?? null;
        $liveMode = $data['live_mode'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? $data['payment_id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            amountCents: self::amountToCents($data['transaction_amount'] ?? null),
            currencyId: $currency === null ? null : (string) $currency,
            externalReference: $reference === null ? null : (string) $reference,
            liveMode: is_bool($liveMode) ? $liveMode : null,
        );
    }

    /** Valor em reais (transaction_amount) para centavos, pelo conversor único (`Cents::fromReais`). */
    public static function amountToCents(int|float|string|null $amount): ?int
    {
        return Cents::fromReais($amount);
    }
}
