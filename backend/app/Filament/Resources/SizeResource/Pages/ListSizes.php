<?php

declare(strict_types=1);

namespace App\Filament\Resources\SizeResource\Pages;

use App\Filament\Resources\SizeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSizes extends ListRecords
{
    protected static string $resource = SizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Novo Tamanho / Escala'),
        ];
    }
}
