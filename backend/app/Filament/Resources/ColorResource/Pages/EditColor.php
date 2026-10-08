<?php

declare(strict_types=1);

namespace App\Filament\Resources\ColorResource\Pages;

use App\Filament\Resources\ColorResource;
use App\Models\Color;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditColor extends EditRecord
{
    protected static string $resource = ColorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(fn (Actions\DeleteAction $action, Color $record) => ColorResource::cancelDeleteIfLinked($action, collect([$record]))),
        ];
    }
}
