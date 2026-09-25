<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MaterialType;
use App\Filament\Resources\ColorResource\Pages;
use App\Models\Color;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
