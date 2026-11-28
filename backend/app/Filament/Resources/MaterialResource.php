<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialResource\Pages;
use App\Models\Material;
use App\Support\ContentClaims;
use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Materiais de impressão (M-84). "Remover" é desativar: o material some da loja e das opções do painel,
 * e pedidos antigos continuam com o nome da época. Apagar de vez só quando nenhuma cor e nenhuma peça usa.
 */
class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Catálogo 3D';

    protected static ?string $modelLabel = 'Material';

    protected static ?string $pluralModelLabel = 'Materiais';

    protected static ?int $navigationSort = 3;

    public const HELP_TEXT = 'Mudar o nome ou a descrição muda o que a loja mostra; pedidos antigos continuam com o nome da época.';

    public const IN_USE_MESSAGE = 'Material em uso no catálogo';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Material de impressão')
                    ->description(self::HELP_TEXT)
                    ->schema([
                        Forms\Components\TextInput::make('short_name')
                            ->label('Nome curto')
                            ->required()
                            ->maxLength(40)
                            ->placeholder('Ex: PLA Silk')
                            ->helperText('Aparece nos botões de material da loja e nas listas do painel.')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create') {
                                    $set('code', Material::codeFromName($state));
                                }
                            })
                            ->rules([
                                static::contentClaimsRule(),
                                fn (string $operation): Closure => function (string $attribute, mixed $value, Closure $fail) use ($operation): void {
                                    if ($operation !== 'create') {
                                        return;
                                    }

                                    $problem = static::newCodeProblem(is_string($value) ? $value : '');
                                    if ($problem !== null) {
                                        $fail($problem);
                                    }
                                },
                            ]),

                        Forms\Components\TextInput::make('code')
                            ->label('Código')
                            ->helperText('Gerado do nome curto. Não muda depois de criado (as cores e as peças guardam este código).')
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('full_name')
                            ->label('Nome completo')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Ex: PLA Silk (Acabamento Acetinado)')
                            ->helperText('Aparece na janela da peça, na loja, e fica gravado no pedido.')
                            ->rules([static::contentClaimsRule()]),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Ordem na loja')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(10)
                            ->required()
                            ->helperText('Menor aparece primeiro.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descrição para o cliente')
                            ->required()
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText(self::HELP_TEXT)
                            ->rules([static::contentClaimsRule()]),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Ativo (a loja oferece este material)')
                            ->default(true)
                            ->helperText('Desativado: some da loja e das opções de peça e cor. Pedidos antigos não mudam.'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Código que o nome curto gera para um material novo: vazio ou já usado dá erro no formulário.
     */
    public static function newCodeProblem(string $shortName): ?string
    {
        $code = Material::codeFromName($shortName);

        if ($code === '') {
            return 'Use letras ou números no nome curto: o código do material é feito a partir dele.';
        }

        if (Material::query()->where('code', $code)->exists()) {
            return "Já existe um material com o código \"{$code}\". Mude o nome curto (ex.: \"{$shortName} 2\") ou reative o material que já existe.";
        }

        return null;
    }

    /**
     * Recusa alegação ambiental ou de saúde proibida (M-59) no texto que a loja mostra.
     */
    public static function contentClaimsRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $broken = ContentClaims::brokenRules(is_string($value) ? $value : null);

            if ($broken !== []) {
                $fail(ContentClaims::message($broken));
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('colors'))
            ->columns([
                Tables\Columns\TextColumn::make('short_name')
                    ->label('Nome curto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nome completo')
                    ->wrap(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('colors_count')
                    ->label('Cores')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Peças')
                    ->state(fn (Material $record): int => $record->productsCount())
                    ->badge()
                    ->color('primary'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Situação')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas desativados'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->visible(fn (Material $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('Desativar material?')
                    ->modalDescription('O material some da loja e das opções de peça e cor. Peça que só tem este material sai da loja. Pedidos antigos não mudam. Dá para reativar depois.')
                    ->action(fn (Material $record) => static::setActive($record, false)),
                Tables\Actions\Action::make('activate')
                    ->label('Reativar')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (Material $record): bool => ! $record->is_active)
                    ->action(fn (Material $record) => static::setActive($record, true)),
                Tables\Actions\DeleteAction::make()
                    ->label('Apagar')
                    ->visible(fn (Material $record): bool => ! $record->isInUse())
                    ->before(fn (Tables\Actions\DeleteAction $action, Material $record) => static::cancelDeleteIfInUse($action, $record)),
            ]);
    }

    public static function setActive(Material $material, bool $active): void
    {
        $material->forceFill(['is_active' => $active])->save();

        Notification::make()
            ->success()
            ->title($active ? 'Material reativado' : 'Material desativado')
            ->send();
    }

    /**
     * Material usado por cor ou peça não se apaga (o servidor confere de novo, além do botão escondido):
     * para tirar da venda, desative.
     */
    public static function cancelDeleteIfInUse(Actions\Action|Tables\Actions\Action $action, Material $material): void
    {
        if (! $material->isInUse()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(self::IN_USE_MESSAGE)
            ->body('Nada foi apagado: há cores ou peças com '.$material->short_name.'. Para tirar da venda, use "Desativar".')
            ->persistent()
            ->send();

        $action->cancel();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterials::route('/'),
            'create' => Pages\CreateMaterial::route('/create'),
            'edit' => Pages\EditMaterial::route('/{record}/edit'),
        ];
    }
}
