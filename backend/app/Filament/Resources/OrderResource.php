<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\ProductionWorkflowService;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Produção & Vendas';

    protected static ?string $modelLabel = 'Encomenda';

    protected static ?string $pluralModelLabel = 'Fila de Produção';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        // SEÇÃO 1: DADOS GERAIS DO PEDIDO (BLOQUEADOS PARA EDIÇÃO FINANCEIRA)
                        Forms\Components\Section::make('Identificação da Encomenda')
                            ->description('Dados imutáveis gerados no momento do checkout')
                            ->schema([
                                Forms\Components\TextInput::make('order_number')
                                    ->label('Identificador do Pedido')
                                    ->disabled(),

                                Forms\Components\TextInput::make('tracking_token')
                                    ->label('Token de Acompanhamento do Cliente')
                                    ->disabled(),

                                Forms\Components\Select::make('status')
                                    ->label('Status do Ciclo de Vida')
                                    ->options(OrderStatus::class)
                                    ->disabled()
                                    ->helperText('Alterações de status devem ser realizadas através dos botões de ação na tabela ou cabeçalho.'),

                                Forms\Components\TextInput::make('paid_at')
                                    ->label('Data / Hora da Confirmação de Pagamento')
                                    ->formatStateUsing(fn (mixed $state): string => ! empty($state) ? Carbon::parse($state)->format('d/m/Y H:i:s') : 'Aguardando Pagamento')
                                    ->disabled(),
                            ])
                            ->columns(2),

                        // SEÇÃO 2: DADOS DO CLIENTE
                        Forms\Components\Section::make('Dados do Cliente')
                            ->schema([
                                Forms\Components\TextInput::make('customer_name')
                                    ->label('Nome Completo')
                                    ->disabled(),

                                Forms\Components\TextInput::make('customer_email')
                                    ->label('E-mail')
                                    ->disabled(),

                                Forms\Components\TextInput::make('customer_cpf')
                                    ->label('CPF')
                                    ->disabled(),

                                Forms\Components\TextInput::make('customer_phone')
                                    ->label('Telefone / WhatsApp')
                                    ->disabled(),
                            ])
                            ->columns(2),

                        // SEÇÃO 3: ENDEREÇO DE ENTREGA (JSON)
                        Forms\Components\Section::make('Endereço de Destino')
                            ->schema([
                                Forms\Components\TextInput::make('shipping_address.zip_code')
                                    ->label('CEP')
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_address.street')
                                    ->label('Logradouro')
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_address.number')
                                    ->label('Número')
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_address.complement')
                                    ->label('Complemento')
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_address.neighborhood')
                                    ->label('Bairro')
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_address.city')
                                    ->label('Cidade')
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_address.state')
                                    ->label('UF')
                                    ->disabled(),
                            ])
                            ->columns(3),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        // SEÇÃO 4: RESUMO FINANCEIRO (READ-ONLY)
                        Forms\Components\Section::make('Fechamento Financeiro')
                            ->description('Valores congelados no ato da compra')
                            ->schema([
                                Forms\Components\TextInput::make('total_cents')
                                    ->label('Valor Total Pago')
                                    ->prefix('R$')
                                    ->formatStateUsing(fn (?int $state): string => number_format(((int) $state) / 100, 2, ',', '.'))
                                    ->disabled(),

                                Forms\Components\TextInput::make('shipping_cost_cents')
                                    ->label('Custo de Envio (Frete)')
                                    ->prefix('R$')
                                    ->formatStateUsing(fn (?int $state): string => number_format(((int) $state) / 100, 2, ',', '.'))
                                    ->disabled(),
                            ]),

                        // SEÇÃO 5: EXPEDIÇÃO E RASTREIO POSTAL (EDITÁVEL)
                        Forms\Components\Section::make('Logística & Rastreio')
                            ->schema([
                                Forms\Components\TextInput::make('carrier_tracking_code')
                                    ->label('Código de Rastreio da Transportadora')
                                    ->placeholder('Ex: BR984729104BR')
                                    ->helperText('Insira o código fornecido pelos Correios/Loggi para notificar o comprador.'),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Pré-carrega a marca de revisão de pagamento (evita uma consulta por linha)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withExists([
                'productionLogs as payment_review_exists' => fn (Builder $logs) => $logs->paymentReview(),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('# Encomenda')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Número do pedido copiado!'),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->description(fn (Order $record): string => $record->customer_email),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status de Produção')
                    ->badge(),

                Tables\Columns\TextColumn::make('payment_review')
                    ->label('Pagamento')
                    ->state(fn (Order $record): ?string => $record->isInPaymentReview() ? 'Pagamento em revisão' : null)
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => 'R$ '.number_format($state / 100, 2, ',', '.'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('carrier_tracking_code')
                    ->label('Rastreio Postal')
                    ->placeholder('Não despachado')
                    ->copyable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data da Compra')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Filtrar por Status')
                    ->options(OrderStatus::class),

                Tables\Filters\Filter::make('payment_review')
                    ->label('Pagamento em revisão')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->inPaymentReview()),
            ])
            ->actions([
                // AÇÃO 1: Iniciar Impressão 3D (Registro Atômico em ProductionLog)
                Tables\Actions\Action::make('startPrinting')
                    ->label('Iniciar Impressão')
                    ->icon('heroicon-m-cpu-chip')
                    ->color('info')
                    ->visible(fn (Order $record): bool => self::canMove($record, OrderStatus::IN_QUEUE, OrderStatus::PRINTING))
                    ->requiresConfirmation()
                    ->modalHeading('Iniciar Fabricação 3D na Fazenda')
                    ->modalDescription('Confirmar que a impressora 3D foi abastecida com o filamento correto e o fatiamento foi disparado?')
                    ->modalSubmitActionLabel('Sim, iniciar extrusão')
                    ->action(function (Order $record): void {
                        $notes = 'Impressão 3D iniciada na fazenda de manufatura.';
                        if (! self::applyPanelTransition($record, OrderStatus::IN_QUEUE, OrderStatus::PRINTING, $notes)) {
                            return;
                        }

                        Notification::make()
                            ->title('Extrusão 3D Iniciada!')
                            ->body("A encomenda {$record->order_number} entrou em processo de impressão.")
                            ->success()
                            ->send();
                    }),

                // AÇÃO 2: Transitar para Acabamento & Embalagem
                Tables\Actions\Action::make('startPackaging')
                    ->label('Acabamento & Embalar')
                    ->icon('heroicon-m-gift')
                    ->color('primary')
                    ->visible(fn (Order $record): bool => self::canMove($record, OrderStatus::PRINTING, OrderStatus::FINISHING_AND_PACKAGING))
                    ->action(function (Order $record): void {
                        if (! self::applyPanelTransition($record, OrderStatus::PRINTING, OrderStatus::FINISHING_AND_PACKAGING, 'Peça retirada da mesa de impressão, movida para conferência, acabamento e embalagem.')) {
                            return;
                        }

                        Notification::make()
                            ->title('Peça Impressa com Sucesso!')
                            ->body("Encomenda {$record->order_number} movida para acabamento e embalagem.")
                            ->info()
                            ->send();
                    }),

                // AÇÃO 3: Expedição e Coleta do Código de Rastreio
                Tables\Actions\Action::make('markAsShipped')
                    ->label('Expedir Pedido')
                    ->icon('heroicon-m-truck')
                    ->color('success')
                    ->visible(fn (Order $record): bool => self::canMove($record, OrderStatus::FINISHING_AND_PACKAGING, OrderStatus::SHIPPED))
                    ->form([
                        Forms\Components\TextInput::make('carrier_tracking_code')
                            ->label('Código de Rastreamento (Correios / Loggi / Jadlog)')
                            ->required()
                            ->placeholder('Ex: BR123456789BR')
                            ->helperText('Informe o código de rastreamento postal gerado pela transportadora.'),
                    ])
                    ->action(function (Order $record, array $data): void {
                        // O código de rastreio entra no mesmo UPDATE condicional: só é gravado se a transição acontecer
                        $shipped = self::applyPanelTransition(
                            $record,
                            OrderStatus::FINISHING_AND_PACKAGING,
                            OrderStatus::SHIPPED,
                            "Pacote despachado via transportadora com código de rastreio: {$data['carrier_tracking_code']}",
                            ['carrier_tracking_code' => (string) $data['carrier_tracking_code']],
                        );

                        if (! $shipped) {
                            return;
                        }

                        Notification::make()
                            ->title('Encomenda Despachada!')
                            ->body("Código de rastreio {$data['carrier_tracking_code']} registrado para {$record->order_number}.")
                            ->success()
                            ->send();
                    }),

                // AÇÃO 4: Confirmar a entrega ao cliente
                Tables\Actions\Action::make('markAsDelivered')
                    ->label('Marcar como Entregue')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (Order $record): bool => self::canMove($record, OrderStatus::SHIPPED, OrderStatus::DELIVERED))
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar entrega da encomenda')
                    ->modalDescription('Confirmar que o pacote chegou ao cliente? Essa ação não pode ser desfeita pelo painel.')
                    ->modalSubmitActionLabel('Sim, foi entregue')
                    ->action(function (Order $record): void {
                        if (! self::applyPanelTransition($record, OrderStatus::SHIPPED, OrderStatus::DELIVERED, 'Encomenda entregue ao cliente.')) {
                            return;
                        }

                        Notification::make()
                            ->title('Encomenda Entregue!')
                            ->body("A encomenda {$record->order_number} foi marcada como entregue.")
                            ->success()
                            ->send();
                    }),

                // AÇÃO 5: Cancelar pedido NÃO pago. Pedido pago (estorno) ou em "Pagamento em revisão" não
                // tem este botão: ver "Decisões pendentes" em docs/coordenacao.md.
                Tables\Actions\Action::make('cancelOrder')
                    ->label('Cancelar Pedido')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => self::canMove($record, OrderStatus::PENDING_PAYMENT, OrderStatus::CANCELLED)
                        && ! $record->isInPaymentReview())
                    ->requiresConfirmation()
                    ->modalHeading('Cancelar pedido não pago')
                    ->modalDescription("Use só para pedido que não foi pago. Se o cliente pagar depois (ex.: PIX já gerado), o pagamento aparece em 'Pagamento em revisão' para estorno.")
                    ->modalSubmitActionLabel('Sim, cancelar pedido')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo do cancelamento')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Fica só no histórico interno; o cliente vê apenas que o pedido foi cancelado.'),
                    ])
                    ->action(function (Order $record, array $data): void {
                        // Camada 2: o mesmo UPDATE condicional exige que o pedido não tenha entrado em revisão
                        // (o webhook pode ter registrado um pagamento divergente depois que a tela abriu)
                        $cancelled = self::applyPanelTransition(
                            $record,
                            OrderStatus::PENDING_PAYMENT,
                            OrderStatus::CANCELLED,
                            "Pedido cancelado pelo painel. Motivo: {$data['reason']}",
                            guard: fn (Builder $query): Builder => $query->whereDoesntHave(
                                'productionLogs',
                                fn (Builder $logs): Builder => $logs->paymentReview(),
                            ),
                        );

                        if (! $cancelled) {
                            return;
                        }

                        Notification::make()
                            ->title('Pedido Cancelado')
                            ->body("A encomenda {$record->order_number} foi cancelada.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Camada 1 (UI): a ação só aparece no status de origem, se a máquina de estados permitir o destino.
     */
    private static function canMove(Order $record, OrderStatus $from, OrderStatus $to): bool
    {
        return $record->status === $from && ProductionWorkflowService::allows($from, $to);
    }

    /**
     * Camada 2 (servidor): muda o status pelo ProductionWorkflowService (regra 5) com a origem que a tela
     * mostrava. Se outro operador ou processo mudou o pedido antes (tela desatualizada), nada é gravado:
     * sem log, sem evento, e o operador é avisado. Os eventos (e-mails) são disparados pelo serviço depois do commit.
     *
     * @param  array<string, mixed>  $extra  colunas gravadas junto com o status
     * @param  (Closure(Builder): Builder)|null  $guard  condição extra no mesmo UPDATE
     */
    private static function applyPanelTransition(Order $record, OrderStatus $from, OrderStatus $to, string $notes, array $extra = [], ?Closure $guard = null): bool
    {
        $applied = app(ProductionWorkflowService::class)->transitionFrom(
            $record,
            $from,
            $to,
            $notes,
            extra: $extra,
            guard: $guard,
        );

        if (! $applied) {
            Notification::make()
                ->title('O status deste pedido mudou. Atualize a página.')
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
