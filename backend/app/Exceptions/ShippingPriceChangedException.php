<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DTOs\ShippingOptionDTO;
use DomainException;

/**
 * O frete exibido ao cliente difere do recalculado no servidor: o pedido não é criado
 * e o cliente precisa confirmar o novo valor (HTTP 409).
 */
class ShippingPriceChangedException extends DomainException
{
    /**
     * @param  array<int, ShippingOptionDTO>  $options
     */
    public function __construct(
        public readonly int $declaredCents,
        public readonly ShippingOptionDTO $serverOption,
        public readonly array $options,
    ) {
        parent::__construct(sprintf(
            'O valor do frete mudou de %s para %s. Confira e confirme o pedido de novo.',
            self::formatCents($declaredCents),
            self::formatCents($serverOption->priceInCents),
        ));
    }

    private static function formatCents(int $cents): string
    {
        return 'R$ '.number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
