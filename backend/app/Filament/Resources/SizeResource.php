<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SizeResource\Pages;
use App\Models\Product;
use App\Models\Size;
use App\Rules\ValidMoneyInput;
use App\Support\MoneyInput;
use Closure;
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
                            ->formatStateUsing(fn (?int $state): string => MoneyInput::format($state))
                            ->rule(new ValidMoneyInput)
                            ->rules([
                                fn (?Size $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    $problem = static::unpricedProductsProblem($record, MoneyInput::toCents($value));

                                    if ($problem !== null) {
                                        $fail($problem);
                                    }
                                },
                            ])
                            ->dehydrateStateUsing(fn (mixed $state): ?int => MoneyInput::toCents($state))
                            ->helperText('Valor adicional somado ao preço base da peça (R$ 0,00 se incluso no valor base). Use vírgula ou ponto para os centavos (ex.: 5,50).'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Acréscimo que deixaria alguma peça ligada a este tamanho abaixo de 1 centavo (o checkout recusaria a compra).
     */
    public static function unpricedProductsProblem(?Size $size, ?int $modifierCents): ?string
    {
        if ($size === null || $modifierCents === null) {
            return null;
        }

        $unpriced = $size->products()
            ->where('base_price_cents', '<', 1 - $modifierCents)
            ->orderBy('title')
            ->get(['products.id', 'products.title', 'products.base_price_cents']);

        if ($unpriced->isEmpty()) {
            return null;
        }

        return 'Com esse valor, estas peças ficariam sem preço: '
            .$unpriced->map(fn (Product $product): string => $product->title.' (R$ '.MoneyInput::format((int) $product->base_price_cents).')')->join(', ', ' e ')
            .'. Use um desconto menor ou tire o tamanho dessas peças.';
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
