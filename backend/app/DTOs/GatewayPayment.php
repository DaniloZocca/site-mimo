<?php

declare(strict_types=1);

namespace App\DTOs;

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

    /**
     * Converte o valor em reais (transaction_amount) para centavos sem aritmética de ponto flutuante.
     * O float do SDK vira texto decimal (15 dígitos significativos, o que remove o ruído binário:
     * 0.1 + 0.2 → "0.3") e o texto é convertido com aritmética inteira. Valores com mais de 2 casas
     * decimais, negativos, em notação científica ou vazios devolvem null (não há valor exato em centavos).
     */
    public static function amountToCents(int|float|string|null $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        if (is_int($amount)) {
            return $amount >= 0 && $amount <= intdiv(PHP_INT_MAX, 100) ? $amount * 100 : null;
        }

        if (is_float($amount)) {
            if (! is_finite($amount)) {
                return null;
            }
            $amount = sprintf('%.15G', $amount);
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m) !== 1) {
            return null;
        }

        // Limite de inteiro: acima disso não há valor exato em centavos
        if (strlen(ltrim($m[1], '0')) > 16) {
            return null;
        }

        $reais = (int) $m[1];
        $centavos = (int) str_pad($m[2] ?? '', 2, '0');

        return $reais * 100 + $centavos;
    }
}
