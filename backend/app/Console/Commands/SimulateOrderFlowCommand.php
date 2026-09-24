<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\CheckoutItemDTO;
use App\DTOs\CheckoutOrderDTO;
use App\Enums\OrderStatus;
use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use App\Models\Size;
use App\Services\OrderCreationService;
use App\Services\ShippingCalculationService;
use Database\Seeders\E2ETestDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

class SimulateOrderFlowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mimoo:simulate-order-flow {--method=pix : pix ou credit_card}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simula o ciclo de vida completo de uma encomenda 3D: Catálogo, Frete, Checkout e Webhook com HMAC SHA-256.';

    /**
     * Execute the console command.
     */
    public function handle(
        ShippingCalculationService $shippingService,
        OrderCreationService $orderCreationService
    ): int {
        // Simulação local: nunca roda em produção (o pagamento aprovado é falso).
        if (app()->isProduction()) {
            $this->error('❌ mimoo:simulate-order-flow não pode ser executado em produção.');

            return Command::FAILURE;
        }

        if (empty(config('services.mercadopago.webhook_secret'))) {
            $this->error('❌ Configure MERCADOPAGO_WEBHOOK_SECRET no .env: o webhook rejeita notificações sem segredo (fail-closed).');

            return Command::FAILURE;
        }

        $startTime = microtime(true);
        $method = strtolower((string) $this->option('method'));
        if (! in_array($method, ['pix', 'credit_card'], true)) {
            $method = 'pix';
        }

        $this->info('');
        $this->line('🐮 <bg=magenta;fg=white;options=bold> MIMOO LAB - SIMULAÇÃO INTEGRADA E2E DE ENCOMENDA 3D </>');
        $this->line('────────────────────────────────────────────────────────────────────────');

        // =====================================================================
        // ETAPA 0: PREPARAÇÃO DOS DADOS DE TESTE REALISTAS
        // =====================================================================
        $this->comment('📦 [Etapa 0/3] Verificando catálogo e variações de filamento...');
        $this->callSilent('db:seed', ['--class' => E2ETestDataSeeder::class]);

        /** @var Product $product */
        $product = Product::where('slug', 'mascote-vaquinha-articulada-3d')->firstOrFail();
        /** @var Color $color */
        $color = Color::where('name', 'Roxo MIMOO')->firstOrFail();
        /** @var Size $size */
        $size = Size::where('name', 'Colecionador')->firstOrFail();

        $this->line("   • Produto: <info>{$product->title}</info> (R$ " . number_format($product->base_price_cents / 100, 2, ',', '.') . ")");
        $this->line("   • Cor: <info>{$color->name}</info> ({$color->material_type->value})");
        $this->line("   • Tamanho: <info>{$size->name}</info> (+R$ " . number_format($size->price_modifier_in_cents / 100, 2, ',', '.') . ")");

        // =====================================================================
        // ETAPA 1: CÁLCULO DINÂMICO DE FRETE E CUBAGEM
        // =====================================================================
        $this->info('');
        $this->comment('🚚 [Etapa 1/3] Calculando frete volumétrico e SLA combinado...');
        $destinationCep = '01310100'; // Av. Paulista, São Paulo - SP

        $shippingPayload = [
            [
                'product_id'       => $product->id,
                'size_id'          => $size->id,
                'quantity'         => 1,
                'size_name'        => $size->name,
                'weight_grams'     => $product->weight_grams,
                'print_time_hours' => (int) ceil((float) $product->estimated_print_time_hours),
            ],
        ];

        $shippingOptions = $shippingService->calculate($destinationCep, $shippingPayload);
        
        // Seleciona SEDEX ou a primeira opção disponível
        $chosenShipping = null;
        foreach ($shippingOptions as $opt) {
            if ($opt->serviceCode === 'sedex') {
                $chosenShipping = $opt;
                break;
            }
        }
        $chosenShipping = $chosenShipping ?: $shippingOptions[0];

        $this->line("   • CEP Destino: <info>{$destinationCep}</info>");
        $this->line("   • Modalidade Selecionada: <info>{$chosenShipping->carrierName} {$chosenShipping->serviceName}</info>");
        $this->line("   • Valor do Frete: <info>{$chosenShipping->priceInCents} centavos (R$ " . number_format($chosenShipping->price, 2, ',', '.') . ")</info>");
        $this->line("   • Prazo Desmembrado: 🖨️ <info>{$chosenShipping->manufacturingLeadTimeDays}d manufatura 3D</info> + 🚚 <info>{$chosenShipping->carrierDeliveryDays}d transporte</info> = <options=bold>{$chosenShipping->totalEstimatedDays} dias úteis</>");

        // =====================================================================
        // ETAPA 2: SUBMISSÃO ATÔMICA DO CHECKOUT (ZERO-TRUST PRICING)
        // =====================================================================
        $this->info('');
        $this->comment('💳 [Etapa 2/3] Submetendo checkout e gerando pedido no banco...');

        $dto = new CheckoutOrderDTO(
            customerName: 'Maria Silva Teste',
            customerEmail: 'maria.teste@mimoolab.com.br',
            customerCpf: '52998224725',
            customerPhone: '11999998888',
            shippingAddress: [
                'zip_code'     => '01310-100',
                'street'       => 'Avenida Paulista',
                'number'       => '1000',
                'complement'   => 'Apto 42',
                'neighborhood' => 'Bela Vista',
                'city'         => 'São Paulo',
                'state'        => 'SP',
            ],
            shippingCostCents: $chosenShipping->priceInCents,
            items: [
                new CheckoutItemDTO(
                    productId: $product->id,
                    colorId: $color->id,
                    sizeId: $size->id,
                    quantity: 1,
                ),
            ],
            paymentMethod: $method,
            cardToken: $method === 'credit_card' ? 'mock_card_token_999' : null,
        );

        $order = $orderCreationService->createOrder($dto);

        $subtotalCents = $order->total_cents - $order->shipping_cost_cents;
        $this->line("   • Pedido Gerado: <options=bold;fg=yellow>#{$order->order_number}</>");
        $this->line("   • Status Inicial: <fg=yellow>{$order->status->value}</>");
        $this->line("   • Subtotal Peças: R$ " . number_format($subtotalCents / 100, 2, ',', '.'));
        $this->line("   • Frete: R$ " . number_format($order->shipping_cost_cents / 100, 2, ',', '.'));
        $this->line("   • Total Liquidável: <options=bold;fg=green>R$ " . number_format($order->total_cents / 100, 2, ',', '.') . "</>");
        $this->line("   • Token Público de Rastreio (UUIDv4): <info>{$order->tracking_token}</info>");

        // =====================================================================
        // ETAPA 3: SIMULAÇÃO DO WEBHOOK CRIPTOGRÁFICO (HMAC-SHA256)
        // =====================================================================
        $this->info('');
        $this->comment('🔐 [Etapa 3/3] Simulando Webhook do Mercado Pago com assinatura HMAC-SHA256...');

        $webhookSecret = (string) config('services.mercadopago.webhook_secret');
        $paymentId = random_int(1_000_000_000, 9_999_999_999);
        $dataId = (string) $paymentId;
        $requestId = (string) Str::uuid();
        $ts = time();

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $webhookSecret);
        $xSignature = "ts={$ts},v1={$v1}";

        $webhookPayload = [
            'type' => 'payment',
            'data' => [
                'id' => $dataId,
            ],
        ];

        $server = [
            'HTTP_X_SIGNATURE'  => $xSignature,
            'HTTP_X_REQUEST_ID' => $requestId,
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
        ];

        $request = Request::create(
            '/api/webhooks/mercadopago',
            'POST',
            [],
            [],
            [],
            $server,
            json_encode($webhookPayload)
        );

        // A consulta reversa (GET /v1/payments/{id}) é respondida por um cliente HTTP falso do SDK,
        // registrado só durante esta chamada. O MercadoPagoService não tem nenhum atalho de simulação.
        $previousHttpClient = MercadoPagoConfig::getHttpClient();
        MercadoPagoConfig::setHttpClient($this->approvedPaymentHttpClient($paymentId, $order));

        try {
            $response = app()->handle($request);
        } finally {
            MercadoPagoConfig::setHttpClient($previousHttpClient);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->error("❌ Falha na execução do Webhook (HTTP {$statusCode}): " . $response->getContent());
            return Command::FAILURE;
        }

        // Valida no banco de dados se a transição de estado ocorreu atomicamente
        $order->refresh();

        if ($order->status !== OrderStatus::IN_QUEUE) {
            $this->error("❌ O status do pedido #{$order->order_number} deveria estar em 'in_queue', mas está em '{$order->status->value}'.");
            return Command::FAILURE;
        }

        $logsCount = $order->productionLogs()->count();
        $latestLog = $order->productionLogs()->latest('id')->first();

        $this->line("   • Resposta do Webhook: <fg=green>HTTP {$statusCode} OK</>");
        $this->line("   • Transição de Status: <options=bold;fg=green>pending_payment ➔ {$order->status->value} (Na Fila de Impressão 3D)</>");
        $this->line("   • Data de Liquidação (paid_at): <info>{$order->paid_at->format('d/m/Y H:i:s')}</info>");
        $this->line("   • Logs de Auditoria Gravados: <info>{$logsCount} evento(s)</info>");
        if ($latestLog) {
            $this->line("   • Último Apontamento: <comment>\"{$latestLog->notes}\"</comment>");
        }

        // =====================================================================
        // RELATÓRIO DE SUCESSO
        // =====================================================================
        $totalDuration = round((microtime(true) - $startTime) * 1000, 2);
        $trackingUrl = rtrim(config('app.url', 'http://localhost:5173'), '/') . "/?token={$order->tracking_token}&success=true";

        $this->info('');
        $this->line('🎉 <bg=green;fg=black;options=bold> SUCESSO ABSOLUTO: FLUXO E2E EXECUTADO COM SUCESSO! </>');
        $this->table(
            ['Métrica / Parâmetro', 'Valor do Teste'],
            [
                ['Número da Encomenda', "#{$order->order_number}"],
                ['Método de Pagamento', strtoupper($method)],
                ['Status Atual da Produção', "🟣 {$order->status->value}"],
                ['Total Liquidado', "R$ " . number_format($order->total_cents / 100, 2, ',', '.')],
                ['URL Pública de Rastreio', $trackingUrl],
                ['Tempo de Execução', "{$totalDuration} ms"],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Cliente HTTP do SDK que responde qualquer requisição com o pagamento aprovado do pedido simulado.
     */
    private function approvedPaymentHttpClient(int $paymentId, Order $order): MPHttpClient
    {
        return new class($paymentId, $order) implements MPHttpClient
        {
            public function __construct(private int $paymentId, private Order $order) {}

            public function send(MPRequest $request): MPResponse
            {
                return new MPResponse(200, [
                    'id'                 => $this->paymentId,
                    'status'             => 'approved',
                    'status_detail'      => 'accredited',
                    'external_reference' => $this->order->order_number,
                    'transaction_amount' => $this->order->total_cents / 100,
                    'currency_id'        => 'BRL',
                    'live_mode'          => false,
                ]);
            }
        };
    }
}
