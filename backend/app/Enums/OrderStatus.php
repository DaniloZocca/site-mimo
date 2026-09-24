<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case PENDING_PAYMENT         = 'pending_payment';
    case IN_QUEUE                = 'in_queue';
    case PRINTING                = 'printing';
    case FINISHING_AND_PACKAGING = 'finishing_and_packaging';
    case SHIPPED                 = 'shipped';
    case DELIVERED               = 'delivered';
    case CANCELLED               = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING_PAYMENT         => 'Aguardando Pagamento',
            self::IN_QUEUE                => 'Na Fila de Impressão 3D',
            self::PRINTING                => 'Imprimindo na Fazenda 3D',
            self::FINISHING_AND_PACKAGING => 'Acabamento & Embalagem',
            self::SHIPPED                 => 'Expedido / Em Trânsito',
            self::DELIVERED               => 'Entregue com Sucesso',
            self::CANCELLED               => 'Cancelado',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING_PAYMENT         => 'warning',
            self::IN_QUEUE                => 'gray',
            self::PRINTING                => 'info',
            self::FINISHING_AND_PACKAGING => 'primary',
            self::SHIPPED                 => 'success',
            self::DELIVERED               => 'success',
            self::CANCELLED               => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PENDING_PAYMENT         => 'heroicon-m-clock',
            self::IN_QUEUE                => 'heroicon-m-queue-list',
            self::PRINTING                => 'heroicon-m-cpu-chip',
            self::FINISHING_AND_PACKAGING => 'heroicon-m-gift',
            self::SHIPPED                 => 'heroicon-m-truck',
            self::DELIVERED               => 'heroicon-m-check-circle',
            self::CANCELLED               => 'heroicon-m-x-circle',
        };
    }
}
