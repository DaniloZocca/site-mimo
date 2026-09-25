<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class CheckoutItemDTO
{
    public function __construct(
        public string $productId,
        public string $colorId,
        public ?string $sizeId,
        public int $quantity,
        public ?string $material = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: (string) $data['product_id'],
            colorId: (string) $data['color_id'],
            sizeId: isset($data['size_id']) && ! empty($data['size_id']) ? (string) $data['size_id'] : null,
            quantity: max(1, (int) ($data['quantity'] ?? 1)),
            material: isset($data['material']) && ! empty($data['material']) ? (string) $data['material'] : null,
        );
    }
}
