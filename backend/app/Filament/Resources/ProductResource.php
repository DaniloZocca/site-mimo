<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MaterialType;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Catálogo 3D';
    protected static ?string $modelLabel = 'Produto';
    protected static ?string $pluralModelLabel = 'Produtos';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Produto')
                    ->tabs([
                        // ABA 1: DADOS GERAIS
                        Forms\Components\Tabs\Tab::make('Dados Gerais')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Título do Produto')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Ex: Vasinho Vaquinha para Suculentas')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                                        if ($operation === 'create' || empty($state)) {
                                            $set('slug', Str::slug((string) $state));
                                        }
                                    }),

                                Forms\Components\TextInput::make('slug')
                                    ->label('Slug (URL Amigável)')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Identificador único para a URL da página do produto.'),

                                Forms\Components\RichEditor::make('description')
                                    ->label('Descrição Detalhada da Peça')
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'bulletList',
                                        'orderedList',
                                        'h2',
                                        'h3',
                                        'link',
                                    ])
                                    ->columnSpanFull(),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Disponível no Catálogo Online')
                                    ->default(true)
                                    ->helperText('Desmarque para ocultar o produto da loja sem deletar seu histórico.'),
                            ])
                            ->columns(2),

                        // ABA 2: PARÂMETROS FABRIS E PRECIFICAÇÃO
                        Forms\Components\Tabs\Tab::make('Parâmetros Fabris')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Forms\Components\TextInput::make('base_price_cents')
                                    ->label('Preço Base de Venda')
                                    ->prefix('R$')
                                    ->placeholder('0,00')
                                    ->required()
                                    ->formatStateUsing(fn (?int $state): string => number_format(($state ?? 0) / 100, 2, ',', '.'))
                                    ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) str_replace(['.', ','], ['', '.'], (string) $state)) * 100))
                                    ->helperText('Valor em Reais (armazenado em centavos para precisão contábil).'),

                                Forms\Components\TextInput::make('weight_grams')
                                    ->label('Peso da Peça (Consumo de Filamento)')
                                    ->suffix('gramas')
                                    ->numeric()
                                    ->default(120)
                                    ->required()
                                    ->helperText('Peso físico da peça para cálculo automático de frete e custos.'),

                                Forms\Components\TextInput::make('estimated_print_time_hours')
                                    ->label('Tempo Médio de Impressão 3D')
                                    ->suffix('horas')
                                    ->numeric()
                                    ->default(14.0)
                                    ->required()
                                    ->helperText('Horas que a máquina FDM levará para manufaturar a unidade.'),
                            ])
                            ->columns(3),

                        // ABA 3: RELAÇÕES E MATERIAIS (CATEGORIAS, MATERIAIS, CORES E TAMANHOS)
                        Forms\Components\Tabs\Tab::make('Categorias & Variações')
                            ->icon('heroicon-o-swatch')
                            ->schema([
                                Forms\Components\CheckboxList::make('categories')
                                    ->relationship('categories', 'name')
                                    ->label('Categorias do Produto')
                                    ->columns(3)
                                    ->gridDirection('row')
                                    ->required()
                                    ->helperText('Selecione uma ou mais categorias onde este produto será exibido na loja.'),

                                Forms\Components\CheckboxList::make('allowed_materials')
                                    ->label('Materiais Homologados para esta Peça')
                                    ->options(collect(MaterialType::cases())->mapWithKeys(
                                        fn (MaterialType $type): array => [$type->value => $type->label()]
                                    ))
                                    ->descriptions(collect(MaterialType::cases())->mapWithKeys(
                                        fn (MaterialType $type): array => [$type->value => $type->technicalDescription()]
                                    ))
                                    ->default(['pla'])
                                    ->columns(2)
                                    ->required()
                                    ->helperText('Marque todos os materiais em que esta peça pode ser manufaturada na oficina.'),

                                Forms\Components\Select::make('sizes')
                                    ->label('Tamanhos / Escalas Permitidas')
                                    ->relationship('sizes', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->required()
                                    ->helperText('Selecione as variações de tamanho aceitas para este modelo.'),

                                Forms\Components\CheckboxList::make('colors')
                                    ->label('Cores de Filamento Disponíveis para Impressão')
                                    ->relationship('colors', 'name')
                                    ->columns(3)
                                    ->gridDirection('row')
                                    ->required()
                                    ->helperText('Marque as cores de bobina que o cliente pode escolher para imprimir esta peça.'),
                            ]),

                        // ABA 4: PIPELINE SEGURO DE UPLOAD DE IMAGENS (ANTI-XSS / 30MB / NO SVG / COMPACT GRID)
                        Forms\Components\Tabs\Tab::make('Fotos do Protótipo')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\Repeater::make('images')
                                    ->relationship('images')
                                    ->label('Galeria de Imagens do Protótipo')
                                    ->addActionLabel('Adicionar Nova Fotografia')
                                    ->defaultItems(1)
                                    ->orderColumn('display_order')
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['alt_text'] ?? 'Nova Imagem')
                                    ->grid(2) // Organiza em cards lado a lado para melhor visualização
                                    ->schema([
                                        Forms\Components\FileUpload::make('image_path')
                                            ->label('Fotografia da Peça Real')
                                            ->image()
                                            ->disk('public')
                                            ->directory('products')
                                            ->visibility('public')
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                            ->maxSize(51200) // 50MB
                                            ->preserveFilenames(false)
                                            ->imagePreviewHeight('220')
                                            ->imageEditor()
                                            ->imageEditorAspectRatios([
                                                null,  // Corte Livre / Personalizado
                                                '1:1', // Quadrado padrão de loja
                                                '4:3',
                                                '16:9',
                                            ])
                                            ->imageEditorEmptyFillColor('#000000')
                                            ->panelLayout('compact')
                                            ->openable()
                                            ->downloadable()
                                            ->required(),

                                        Forms\Components\TextInput::make('alt_text')
                                            ->label('Texto Alternativo (Acessibilidade & SEO)')
                                            ->placeholder('Ex: Vasinho vaquinha lilás com acabamento acetinado')
                                            ->maxLength(255),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('primaryImage.image_path')
                    ->label('Foto')
                    ->disk('public')
                    ->circular(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Título do Produto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('categories.name')
                    ->label('Categorias')
                    ->badge()
                    ->color('primary')
                    ->separator(', '),

                Tables\Columns\TextColumn::make('allowed_materials')
                    ->label('Materiais')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => MaterialType::tryFrom((string) $state)?->shortLabel() ?? (string) $state)
                    ->color(fn (?string $state): string => MaterialType::tryFrom((string) $state)?->getColor() ?? 'gray')
                    ->separator(', '),

                Tables\Columns\TextColumn::make('colors_count')
                    ->counts('colors')
                    ->label('Cores')
                    ->badge()
                    ->color('success')
                    ->suffix(' cores')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sizes_count')
                    ->counts('sizes')
                    ->label('Tamanhos')
                    ->badge()
                    ->color('warning')
                    ->suffix(' escalas')
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_price_cents')
                    ->label('Preço Base')
                    ->formatStateUsing(fn (int $state): string => 'R$ ' . number_format($state / 100, 2, ',', '.'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('weight_grams')
                    ->label('Peso')
                    ->suffix('g')
                    ->sortable(),

                Tables\Columns\TextColumn::make('estimated_print_time_hours')
                    ->label('SLA Impressão')
                    ->suffix('h')
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Ativo na Loja'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status no Catálogo')
                    ->trueLabel('Apenas Ativos')
                    ->falseLabel('Apenas Inativos'),
                Tables\Filters\SelectFilter::make('categories')
                    ->label('Filtrar por Categoria')
                    ->relationship('categories', 'name'),
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
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
