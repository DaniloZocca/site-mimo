<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * M-51: Access Token do Mercado Pago vazio ou de exemplo (`TEST-000000`) fora de `local`/`testing`.
 * Nenhuma chamada é feita ao gateway; o checkout trata como falha de gateway (502, pedido pendente) e o webhook
 * (M-52) como falha na consulta reversa (422, o Mercado Pago reenvia).
 */
class PaymentGatewayNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mercado Pago sem Access Token configurado.');
    }
}
