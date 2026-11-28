<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaterialResource\Pages;

use App\Filament\Resources\MaterialResource;
use App\Models\Material;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMaterial extends EditRecord
{
    protected static string $resource = MaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Apagar')
                ->visible(fn (Material $record): bool => ! $record->isInUse())
                ->before(fn (Actions\DeleteAction $action, Material $record) => MaterialResource::cancelDeleteIfInUse($action, $record)),
        ];
    }

    /**
     * O código não muda depois de criado (cores e peças guardam o código), mesmo que o formulário mande.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['code']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
