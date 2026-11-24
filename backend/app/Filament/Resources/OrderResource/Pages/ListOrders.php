<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    // M-69: a fila ocupa a largura toda da tela para a coluna de ações caber inteira
    protected ?string $maxContentWidth = 'full';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
