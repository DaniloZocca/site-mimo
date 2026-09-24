<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Support;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;

/**
 * Monta catálogo e payloads de POST /api/checkout no formato aninhado aceito pelo CheckoutRequest.
 */
trait BuildsCheckoutPayload
{
    /** CPF válido (dígitos verificadores corretos). */
    protected const VALID_CPF = '529.982.247-25';

    protected const VALID_CPF_DIGITS = '52998224725';

    /**
     * Produto ativo com uma cor vinculada (em estoque) e, opcionalmente, um tamanho vinculado.
     *
     * @param  array<string, mixed>  $productAttributes
     * @return array{product: Product, color: Color, size: ?Size}
     */
    protected function catalogItem(array $productAttributes = [], ?int $sizeModifierCents = null): array
    {
        $product = Product::factory()->create($productAttributes);
        $color = Color::factory()->create();
        $product->colors()->attach($color->id);

        $size = null;
        if ($sizeModifierCents !== null) {
            $size = Size::factory()->modifier($sizeModifierCents)->create();
            $product->sizes()->attach($size->id);
        }

        return ['product' => $product, 'color' => $color, 'size' => $size];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function itemPayload(Product $product, Color $color, ?Size $size = null, int $quantity = 1, string $material = 'pla', array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size?->id,
            'material' => $material,
            'quantity' => $quantity,
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides  mesclado na raiz (array_replace_recursive)
     * @return array<string, mixed>
     */
    protected function checkoutPayload(array $items, string $method = 'pix', int $shippingCents = 1500, array $overrides = []): array
    {
        $payment = ['payment_method' => $method];

        if ($method === 'credit_card') {
            $payment['card_token'] = 'card-token-characterization';
            $payment['installments'] = 1;
            $payment['payment_method_id'] = 'visa';
        }

        $payload = [
            'customer' => [
                'name' => 'Fernanda Souza Lima',
                'email' => 'fernanda@example.com',
                'cpf' => self::VALID_CPF,
                'phone' => '(11) 98888-7777',
            ],
            'shipping' => [
                'zip_code' => '01001-000',
                'street' => 'Praça da Sé',
                'number' => '100',
                'complement' => 'Apto 12',
                'neighborhood' => 'Sé',
                'city' => 'São Paulo',
                'state' => 'sp',
            ],
            'shipping_cost_cents' => $shippingCents,
            'items' => $items,
            'payment' => $payment,
        ];

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * Payload completo de 1 item simples (produto 5000, sem tamanho).
     *
     * @return array{payload: array<string, mixed>, product: Product, color: Color}
     */
    protected function simpleCheckout(string $method = 'pix', int $shippingCents = 1500): array
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();

        return [
            'payload' => $this->checkoutPayload([$this->itemPayload($product, $color)], $method, $shippingCents),
            'product' => $product,
            'color' => $color,
        ];
    }
}
