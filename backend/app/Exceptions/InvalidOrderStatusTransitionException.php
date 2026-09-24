<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;
use DomainException;

class InvalidOrderStatusTransitionException extends DomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            "Transição de status inválida no fluxo produtivo 3D: '{$from->getLabel()}' ({$from->value}) para '{$to->getLabel()}' ({$to->value})."
        );
    }
}
