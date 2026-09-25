<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SizeResource\Pages;
use App\Models\Size;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SizeResource extends Resource
{
    protected static ?string $model = Size::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-out';

    protected static ?string $navigationGroup = 'Catálogo 3D';

    protected static ?string $modelLabel = 'Tamanho / Escala';

    protected static ?string $pluralModelLabel = 'Tamanhos / Escalas';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Especificações da Escala')
                    ->description('Defina tamanhos padronizados e os multiplicadores de preço/tempo de impressão')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome do Tamanho / Escala')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Ex: Pequeno (8cm), Médio Padrão (12cm), Maxi (22cm)'),

                        Forms\Components\TextInput::make('price_modifier_in_cents')
                            ->label('Acréscimo no Preço de Venda')
                            ->prefix('R$')
                            ->placeholder('0,00')
                            ->required()
                            ->default(0)
                            ->formatStateUsing(fn (?int $state): string => number_format(($state ?? 0) / 100, 2, ',', '.'))
                            ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) str_replace(['.', ','], ['', '.'], (string) $state)) * 100))
                            ->helperText('Valor adicional somado ao preço base da peça (R$ 0,00 se incluso no valor base)'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tamanho / Escala')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('price_modifier_in_cents')
                    ->label('Adicional de Preço')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+ R$ '.number_format($state / 100, 2, ',', '.') : 'Incluso no Base')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Peças Vinculadas')
                    ->badge()
                    ->color('success')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Apenas Ativos')
                    ->falseLabel('Apenas Inativos'),
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
            'index' => Pages\ListSizes::route('/'),
            'create' => Pages\CreateSize::route('/create'),
            'edit' => Pages\EditSize::route('/{record}/edit'),
        ];
    }
}
