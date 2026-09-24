<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\CheckoutRequest;

readonly class CheckoutOrderDTO
{
    /**
     * @param array<int, CheckoutItemDTO> $items
     * @param array<string, mixed> $shippingAddress
     */
    public function __construct(
        public string $customerName,
        public string $customerEmail,
        public string $customerCpf,
        public string $customerPhone,
        public array $shippingAddress,
        public int $shippingCostCents,
        public array $items,
        public string $paymentMethod,
        public ?string $cardToken = null,
        public ?string $paymentMethodId = null,
        public int $installments = 1,
        public string $shippingServiceCode = '',
        public ?int $declaredShippingCostCents = null,
    ) {}

    public static function fromRequest(CheckoutRequest $request): self
    {
        $validated = $request->validated();
        $customer  = $validated['customer'];
        $shipping  = $validated['shipping'];
        $payment   = $validated['payment'];

        $items = array_map(
            fn (array $item): CheckoutItemDTO => CheckoutItemDTO::fromArray($item),
            $validated['items']
        );

        $shippingAddress = [
            'zip_code'     => (string) $shipping['zip_code'],
            'street'       => (string) $shipping['street'],
            'number'       => (string) $shipping['number'],
            'complement'   => isset($shipping['complement']) ? (string) $shipping['complement'] : null,
            'neighborhood' => (string) $shipping['neighborhood'],
            'city'         => (string) $shipping['city'],
            'state'        => strtoupper((string) $shipping['state']),
        ];

        return new self(
            customerName:      (string) $customer['name'],
            customerEmail:     (string) $customer['email'],
            customerCpf:       (string) $customer['cpf'],
            customerPhone:     (string) $customer['phone'],
            shippingAddress:   $shippingAddress,
            // Frete cobrado vem do servidor (ShippingCalculationService); o do cliente só detecta divergência.
            shippingCostCents: 0,
            items:             $items,
            paymentMethod:     (string) $payment['payment_method'],
            cardToken:         isset($payment['card_token']) ? (string) $payment['card_token'] : null,
            paymentMethodId:   isset($payment['payment_method_id']) ? (string) $payment['payment_method_id'] : null,
            installments:      max(1, (int) ($payment['installments'] ?? 1)),
            shippingServiceCode: strtolower(trim((string) $validated['shipping_service'])),
            declaredShippingCostCents: isset($validated['shipping_cost_cents']) ? (int) $validated['shipping_cost_cents'] : null,
        );
    }
}
