<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CheckoutOrderDTO;
use App\DTOs\ShippingOptionDTO;
use App\Enums\MaterialType;
use App\Enums\OrderStatus;
use App\Exceptions\ShippingPriceChangedException;
use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use App\Models\Size;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderCreationService
{
    public function __construct(
        private readonly ShippingCalculationService $shippingService,
    ) {}

    /**
     * Zero-Trust do frete: recalcula as opções no servidor (mesmos itens e cubagem da cotação,
     * CEP do endereço de entrega) e devolve o serviço escolhido. Chamada externa (Melhor Envio):
     * deve rodar ANTES e FORA de qualquer transação de banco.
     *
     * @throws ValidationException serviço inexistente/indisponível para o CEP (422)
     * @throws ShippingPriceChangedException valor exibido ao cliente diverge do recalculado (409)
     */
    public function resolveShipping(CheckoutOrderDTO $dto): ShippingOptionDTO
    {
        $quoteItems = array_map(fn ($item): array => [
            'product_id' => $item->productId,
            'size_id' => $item->sizeId,
            'quantity' => $item->quantity,
        ], $dto->items);

        ['option' => $option, 'options' => $options] = $this->shippingService->findOption(
            (string) ($dto->shippingAddress['zip_code'] ?? ''),
            $quoteItems,
            $dto->shippingServiceCode,
        );

        if (! $option) {
            throw ValidationException::withMessages([
                'shipping_service' => ['A opção de frete escolhida não está disponível para este CEP. Escolha outra opção.'],
            ]);
        }

        $source = ShippingCalculationService::priceSource($option);

        if ($dto->declaredShippingCostCents !== null && $dto->declaredShippingCostCents !== $option->priceInCents) {
            Log::warning('Checkout recusado: frete exibido diverge do recalculado no servidor.', [
                'shipping_service' => $option->serviceCode,
                'declared_cents' => $dto->declaredShippingCostCents,
                'server_cents' => $option->priceInCents,
                'shipping_price_source' => $source,
            ]);

            throw new ShippingPriceChangedException($dto->declaredShippingCostCents, $option, $options);
        }

        Log::info('Frete do checkout recalculado no servidor.', [
            'shipping_service' => $option->serviceCode,
            'server_cents' => $option->priceInCents,
            'shipping_price_source' => $source,
        ]);

        return $option;
    }

    /**
     * Cria e persiste o pedido de forma determinística e atômica.
     * Regra Zero-Trust: Recalcula todos os valores financeiros diretamente no banco
     * e grava snapshots imutáveis de cada item encomendado.
     *
     * $shipping: opção recalculada por resolveShipping() (caminho do checkout). Sem ela, usa
     * $dto->shippingCostCents (apenas chamadores internos que já calcularam o frete no servidor).
     *
     * @throws ValidationException|Exception
     */
    public function createOrder(
        CheckoutOrderDTO $dto,
        ?ShippingOptionDTO $shipping = null,
        ?string $idempotencyKey = null,
        ?string $requestFingerprint = null,
    ): Order {
        // 1. Pré-carregamento em lote para prevenir N+1 queries
        $productIds = array_map(fn ($item) => $item->productId, $dto->items);
        $colorIds = array_map(fn ($item) => $item->colorId, $dto->items);
        $sizeIds = array_filter(array_map(fn ($item) => $item->sizeId, $dto->items));

        // Cores e tamanhos ligados a cada produto (pivôs color_product / product_size) numa consulta só
        $products = Product::with(['colors', 'sizes'])->whereIn('id', $productIds)->get()->keyBy('id');
        $colors = Color::whereIn('id', $colorIds)->get()->keyBy('id');
        $sizes = ! empty($sizeIds) ? Size::whereIn('id', $sizeIds)->get()->keyBy('id') : collect();

        $subtotalPiecesCents = 0;
        $itemsToPersist = [];

        // 2. Zero-Trust Pricing: Recálculo de preços, validação de catálogo e auditoria de materiais
        foreach ($dto->items as $index => $item) {
            /** @var Product|null $product */
            $product = $products->get($item->productId);

            if (! $product || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["O produto #{$item->productId} não está mais disponível no catálogo."],
                ]);
            }

            /** @var Color|null $color */
            $color = $colors->get($item->colorId);
            if (! $color || ! $color->in_stock) {
                throw ValidationException::withMessages([
                    'items' => ["A cor selecionada para o produto '{$product->title}' está esgotada no estoque."],
                ]);
            }

            // A cor precisa estar ligada ao produto no painel (produto sem cores ligadas não é vendável)
            if (! $product->colors->contains('id', $color->id)) {
                throw ValidationException::withMessages([
                    "items.{$index}.color_id" => ["A cor {$color->name} não está disponível para {$product->title}."],
                ]);
            }

            /** @var Size|null $size */
            $size = $item->sizeId ? $sizes->get($item->sizeId) : null;
            if ($item->sizeId && ! $size) {
                throw ValidationException::withMessages([
                    'items' => ["A variação de tamanho informada para o produto '{$product->title}' não foi encontrada."],
                ]);
            }

            // Produto com tamanhos ligados exige um deles; sem tamanhos ligados vale o "Tamanho Padrão" (modificador 0)
            if (! $size && $product->sizes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    "items.{$index}.size_id" => ["Escolha um tamanho para {$product->title}."],
                ]);
            }

            if ($size && ! $product->sizes->contains('id', $size->id)) {
                throw ValidationException::withMessages([
                    "items.{$index}.size_id" => ["O tamanho {$size->name} não está disponível para {$product->title}."],
                ]);
            }

            // Normalização e validação do polímero contra allowed_materials do modelo 3D
            $materialValue = strtolower(trim((string) ($item->material ?? 'pla')));
            $materialEnum = MaterialType::tryFrom($materialValue);

            if (! $materialEnum) {
                throw ValidationException::withMessages([
                    'items' => ["O material '{$materialValue}' não é um polímero reconhecido pela MIMOO LAB."],
                ]);
            }

            $allowedMaterials = (array) ($product->allowed_materials ?? ['pla']);
            $normalizedAllowed = array_map(
                fn ($mat) => $mat instanceof MaterialType ? $mat->value : strtolower((string) $mat),
                $allowedMaterials
            );

            if (! in_array($materialEnum->value, $normalizedAllowed, true)) {
                $allowedLabels = implode(', ', array_map('strtoupper', $normalizedAllowed));
                throw ValidationException::withMessages([
                    'items' => ["O material '{$materialEnum->shortLabel()}' não é autorizado para fabricação da peça '{$product->title}'. Materiais permitidos para este modelo: {$allowedLabels}."],
                ]);
            }

            // O filamento da cor precisa ser do material escolhido (cor sem material conhecido é recusada)
            if ($color->material_type !== $materialEnum) {
                throw ValidationException::withMessages([
                    "items.{$index}.material" => ["A cor {$color->name} não está disponível em {$materialEnum->shortLabel()} para {$product->title}."],
                ]);
            }

            // Recalcula o Preço Unitário: Product::base_price_cents + Size::price_modifier_in_cents
            $unitPriceCents = (int) $product->base_price_cents + (int) ($size?->price_modifier_in_cents ?? 0);

            // Peça sem preço (base + acréscimo abaixo de 1 centavo) não é vendável: não vira desconto nas outras peças
            if ($unitPriceCents < 1) {
                throw ValidationException::withMessages($size
                    ? ["items.{$index}.size_id" => ["O tamanho {$size->name} de {$product->title} está indisponível no momento. Tire do carrinho ou escolha outro tamanho."]]
                    : ["items.{$index}.product_id" => ["A peça {$product->title} está indisponível no momento. Tire do carrinho para continuar."]]);
            }

            $subtotalPiecesCents += ($unitPriceCents * $item->quantity);

            // Monta o snapshot fixo da peça para gravação em order_items
            $itemsToPersist[] = [
                'product_id' => $product->id,
                'color_id' => $color->id,
                'size_id' => $size?->id,
                'product_title_snapshot' => $product->title,
                'color_name_snapshot' => $color->name,
                'size_name_snapshot' => $size?->name ?? 'Tamanho Padrão',
                'material_name_snapshot' => $materialEnum->label(),
                'unit_price_cents' => $unitPriceCents,
                'quantity' => $item->quantity,
            ];
        }

        // 3. Aplicação do desconto de 10% no valor das peças para pagamento via PIX
        $isPix = strtolower((string) $dto->paymentMethod) === 'pix';
        $discountCents = 0;

        if ($isPix) {
            $discountCents = (int) round($subtotalPiecesCents * 0.10);
        }

        $piecesTotalAfterDiscount = max(0, $subtotalPiecesCents - $discountCents);
        $shippingCostCents = $shipping !== null ? $shipping->priceInCents : (int) $dto->shippingCostCents;
        $totalCents = $piecesTotalAfterDiscount + $shippingCostCents;

        // 4. Geração de Identificador Único à Prova de Colisão (Prefixo + ULID)
        $orderNumber = 'MIMOO-'.strtoupper(substr((string) Str::ulid(), -8));

        // 5. Persistência Atômica dentro de transação PostgreSQL
        /** @var Order $order */
        $order = DB::transaction(function () use ($dto, $orderNumber, $totalCents, $shippingCostCents, $shipping, $itemsToPersist, $isPix, $discountCents, $idempotencyKey, $requestFingerprint): Order {
            $order = new Order;
            $order->order_number = $orderNumber;
            $order->tracking_token = (string) Str::uuid();
            $order->customer_name = $dto->customerName;
            $order->customer_email = $dto->customerEmail;
            $order->customer_cpf = $dto->customerCpf;
            $order->customer_phone = $dto->customerPhone;
            $order->shipping_address = $dto->shippingAddress;
            $order->status = OrderStatus::PENDING_PAYMENT;
            $order->total_cents = $totalCents;
            $order->shipping_cost_cents = $shippingCostCents;

            // Idempotência: UNIQUE em idempotency_key barra o segundo pedido da mesma tentativa;
            // payment_idempotency_key vai no X-Idempotency-Key de toda criação de pagamento deste pedido.
            $order->idempotency_key = $idempotencyKey;
            $order->request_fingerprint = $requestFingerprint;
            $order->payment_idempotency_key = (string) Str::uuid();

            if ($shipping !== null) {
                $order->shipping_service_code = $shipping->serviceCode;
                $order->shipping_service_name = $shipping->serviceName;
                $order->shipping_carrier_name = $shipping->carrierName;
                $order->shipping_delivery_days = $shipping->totalEstimatedDays;
                $order->shipping_price_source = ShippingCalculationService::priceSource($shipping);
            }

            $order->save();

            // Gravação dos itens com snapshots imutáveis em order_items
            foreach ($itemsToPersist as $itemData) {
                $order->items()->create([
                    'product_id' => $itemData['product_id'],
                    'color_id' => $itemData['color_id'],
                    'size_id' => $itemData['size_id'],
                    'product_title_snapshot' => $itemData['product_title_snapshot'],
                    'color_name_snapshot' => $itemData['color_name_snapshot'],
                    'size_name_snapshot' => $itemData['size_name_snapshot'],
                    'material_name_snapshot' => $itemData['material_name_snapshot'],
                    'unit_price_cents' => $itemData['unit_price_cents'],
                    'quantity' => $itemData['quantity'],
                ]);
            }

            $discountNote = $isPix && $discountCents > 0
                ? ' (Desconto PIX 10% aplicado: R$ '.number_format($discountCents / 100, 2, ',', '.').')'
                : '';

            $order->productionLogs()->create([
                'previous_status' => 'checkout',
                'new_status' => OrderStatus::PENDING_PAYMENT->value,
                'notes' => "Pedido persistido com snapshots e valores auditados pelo backend{$discountNote}.",
                'created_at' => now(),
            ]);

            return $order;
            // M-45: no MariaDB, envios simultâneos da mesma Idempotency-Key podem dar deadlock (1213) no índice
            // UNIQUE; repetir a transação leva ao caminho normal (duplicata → replay ou 409)
        }, attempts: 3);

        Log::info("✅ Pedido #{$order->order_number} criado com sucesso no banco de dados.", [
            'order_id' => $order->id,
            'total_cents' => $order->total_cents,
            'subtotal_pieces_cents' => $subtotalPiecesCents,
            'discount_cents' => $discountCents,
            'shipping_cost_cents' => $order->shipping_cost_cents,
            'is_pix' => $isPix,
        ]);

        return $order->load(['items', 'productionLogs']);
    }
}
