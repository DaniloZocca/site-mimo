<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaterialResource\Pages;

use App\Filament\Resources\MaterialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaterials extends ListRecords
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Novo material'),
        ];
    }
}
