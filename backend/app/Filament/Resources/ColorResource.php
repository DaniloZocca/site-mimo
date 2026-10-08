<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MaterialType;
use App\Filament\Resources\ColorResource\Pages;
use App\Models\Color;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ColorResource extends Resource
{
    protected static ?string $model = Color::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationGroup = 'Catálogo 3D';

    protected static ?string $modelLabel = 'Cor de Filamento';

    protected static ?string $pluralModelLabel = 'Cores de Filamento';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detalhes do Filamento')
                    ->description('Cadastre as cores de bobina PLA/PETG disponíveis na fazenda de impressão')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome Comercial da Cor')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Ex: Lilás MIMOO, Seda Dourada, Rosa Algodão'),

                        Forms\Components\ColorPicker::make('hex_code')
                            ->label('Código da Cor (Hex / Visual)')
                            ->required(),

                        Forms\Components\Select::make('material_type')
                            ->label('Tipo de Polímero / Material')
                            ->options(MaterialType::class)
                            ->default(MaterialType::PLA)
                            ->required(),

                        Forms\Components\Toggle::make('in_stock')
                            ->label('Disponível em Estoque')
                            ->default(true)
                            ->helperText('Se desmarcado, esta cor não poderá ser selecionada pelos clientes'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('hex_code')
                    ->label('Amostra')
                    ->copyable()
                    ->copyMessage('Código hexadecimal copiado!'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nome da Cor')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('material_type')
                    ->label('Material')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Peças Vinculadas')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('in_stock')
                    ->label('Em Estoque'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('material_type')
                    ->label('Filtrar por Material')
                    ->options(MaterialType::class),

                Tables\Filters\TernaryFilter::make('in_stock')
                    ->label('Status de Estoque')
                    ->trueLabel('Apenas em Estoque')
                    ->falseLabel('Apenas Esgotados'),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(fn (Tables\Actions\DeleteAction $action, Color $record) => static::cancelDeleteIfLinked($action, collect([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(fn (Tables\Actions\DeleteBulkAction $action, Collection $records) => static::cancelDeleteIfLinked($action, $records)),
                ]),
            ]);
    }

    /**
     * Apagar uma cor tira ela de todas as peças (cascade no pivô) e pode deixar peça sem cor,
     * que o checkout recusa. Cor vinculada não se apaga: o painel sugere "esgotada".
     *
     * @param  \Illuminate\Support\Collection<int, Color>  $colors
     */
    public static function cancelDeleteIfLinked(Actions\Action|Tables\Actions\Action|Tables\Actions\BulkAction $action, \Illuminate\Support\Collection $colors): void
    {
        $linked = Color::query()
            ->whereKey($colors->map(fn (Color $color): string => $color->getKey())->all())
            ->has('products')
            ->orderBy('name')
            ->pluck('name');

        if ($linked->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Cor em uso no catálogo')
            ->body('Nada foi apagado. Marcada em peças da loja: '.$linked->join(', ', ' e ').'. Para tirar da venda, desmarque "Em Estoque"; para apagar, desmarque a cor nas peças antes.')
            ->persistent()
            ->send();

        $action->cancel();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListColors::route('/'),
            'create' => Pages\CreateColor::route('/create'),
            'edit' => Pages\EditColor::route('/{record}/edit'),
        ];
    }
}
