<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CheckoutOrderDTO;
use App\Enums\MaterialType;
use App\Enums\OrderStatus;
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
    /**
     * Cria e persiste o pedido de forma determinística e atômica.
     * Regra Zero-Trust: Recalcula todos os valores financeiros diretamente no banco
     * e grava snapshots imutáveis de cada item encomendado.
     *
     * @throws ValidationException|Exception
     */
    public function createOrder(CheckoutOrderDTO $dto): Order
    {
        // 1. Pré-carregamento em lote para prevenir N+1 queries
        $productIds = array_map(fn ($item) => $item->productId, $dto->items);
        $colorIds   = array_map(fn ($item) => $item->colorId, $dto->items);
        $sizeIds    = array_filter(array_map(fn ($item) => $item->sizeId, $dto->items));

        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        $colors   = Color::whereIn('id', $colorIds)->get()->keyBy('id');
        $sizes    = ! empty($sizeIds) ? Size::whereIn('id', $sizeIds)->get()->keyBy('id') : collect();

        $subtotalPiecesCents = 0;
        $itemsToPersist      = [];

        // 2. Zero-Trust Pricing: Recálculo de preços, validação de catálogo e auditoria de materiais
        foreach ($dto->items as $item) {
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

            /** @var Size|null $size */
            $size = $item->sizeId ? $sizes->get($item->sizeId) : null;
            if ($item->sizeId && ! $size) {
                throw ValidationException::withMessages([
                    'items' => ["A variação de tamanho informada para o produto '{$product->title}' não foi encontrada."],
                ]);
            }

            // Normalização e validação do polímero contra allowed_materials do modelo 3D
            $materialValue = strtolower(trim((string) ($item->material ?? 'pla')));
            $materialEnum  = MaterialType::tryFrom($materialValue);

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

            // Recalcula o Preço Unitário: Product::base_price_cents + Size::price_modifier_in_cents
            $unitPriceCents = (int) $product->base_price_cents + (int) ($size?->price_modifier_in_cents ?? 0);
            $subtotalPiecesCents += ($unitPriceCents * $item->quantity);

            // Monta o snapshot fixo da peça para gravação em order_items
            $itemsToPersist[] = [
                'product_id'             => $product->id,
                'color_id'               => $color->id,
                'size_id'                => $size?->id,
                'product_title_snapshot' => $product->title,
                'color_name_snapshot'    => $color->name,
                'size_name_snapshot'     => $size?->name ?? 'Tamanho Padrão',
                'material_name_snapshot' => $materialEnum->label(),
                'unit_price_cents'       => $unitPriceCents,
                'quantity'               => $item->quantity,
            ];
        }

        // 3. Aplicação do desconto de 10% no valor das peças para pagamento via PIX
        $isPix = strtolower((string) $dto->paymentMethod) === 'pix';
        $discountCents = 0;

        if ($isPix) {
            $discountCents = (int) round($subtotalPiecesCents * 0.10);
        }

        $piecesTotalAfterDiscount = max(0, $subtotalPiecesCents - $discountCents);
        $totalCents = $piecesTotalAfterDiscount + (int) $dto->shippingCostCents;

        // 4. Geração de Identificador Único à Prova de Colisão (Prefixo + ULID)
        $orderNumber = 'MIMOO-' . strtoupper(substr((string) Str::ulid(), -8));

        // 5. Persistência Atômica dentro de transação PostgreSQL
        /** @var Order $order */
        $order = DB::transaction(function () use ($dto, $orderNumber, $totalCents, $itemsToPersist, $isPix, $discountCents): Order {
            $order = new Order();
            $order->order_number        = $orderNumber;
            $order->tracking_token      = (string) Str::uuid();
            $order->customer_name       = $dto->customerName;
            $order->customer_email      = $dto->customerEmail;
            $order->customer_cpf        = $dto->customerCpf;
            $order->customer_phone      = $dto->customerPhone;
            $order->shipping_address    = $dto->shippingAddress;
            $order->status              = OrderStatus::PENDING_PAYMENT;
            $order->total_cents         = $totalCents;
            $order->shipping_cost_cents = $dto->shippingCostCents;
            $order->save();

            // Gravação dos itens com snapshots imutáveis em order_items
            foreach ($itemsToPersist as $itemData) {
                $order->items()->create([
                    'product_id'             => $itemData['product_id'],
                    'color_id'               => $itemData['color_id'],
                    'size_id'                => $itemData['size_id'],
                    'product_title_snapshot' => $itemData['product_title_snapshot'],
                    'color_name_snapshot'    => $itemData['color_name_snapshot'],
                    'size_name_snapshot'     => $itemData['size_name_snapshot'],
                    'material_name_snapshot' => $itemData['material_name_snapshot'],
                    'unit_price_cents'       => $itemData['unit_price_cents'],
                    'quantity'               => $itemData['quantity'],
                ]);
            }

            $discountNote = $isPix && $discountCents > 0
                ? " (Desconto PIX 10% aplicado: R$ " . number_format($discountCents / 100, 2, ',', '.') . ")"
                : "";

            $order->productionLogs()->create([
                'previous_status' => 'checkout',
                'new_status'      => OrderStatus::PENDING_PAYMENT->value,
                'notes'           => "Pedido persistido com snapshots e valores auditados pelo backend{$discountNote}.",
                'created_at'      => now(),
            ]);

            return $order;
        });

        Log::info("✅ Pedido #{$order->order_number} criado com sucesso no banco de dados.", [
            'order_id'              => $order->id,
            'total_cents'           => $order->total_cents,
            'subtotal_pieces_cents' => $subtotalPiecesCents,
            'discount_cents'        => $discountCents,
            'shipping_cost_cents'   => $order->shipping_cost_cents,
            'is_pix'                => $isPix,
        ]);

        return $order->load(['items', 'productionLogs']);
    }
}
