<?php

declare(strict_types=1);

namespace App\DTOs;

final class ShippingOptionDTO
{
    public function __construct(
        public readonly string $serviceCode,
        public readonly string $serviceName,
        public readonly string $carrierName,
        public readonly int $priceInCents,
        public readonly float $price,
        public readonly int $carrierDeliveryDays,
        public readonly int $manufacturingLeadTimeDays,
        public readonly int $totalEstimatedDays,
        public readonly ?string $iconUrl = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Campos do DTO para o cache, só com valores simples (o cache não desserializa objetos).
     *
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'service_name' => $this->serviceName,
            'carrier_name' => $this->carrierName,
            'price_in_cents' => $this->priceInCents,
            'price' => $this->price,
            'carrier_delivery_days' => $this->carrierDeliveryDays,
            'manufacturing_lead_time_days' => $this->manufacturingLeadTimeDays,
            'total_estimated_days' => $this->totalEstimatedDays,
            'icon_url' => $this->iconUrl,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Remonta o DTO a partir de toCacheArray().
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromCacheArray(array $data): self
    {
        return new self(
            serviceCode: $data['service_code'],
            serviceName: $data['service_name'],
            carrierName: $data['carrier_name'],
            priceInCents: $data['price_in_cents'],
            price: $data['price'],
            carrierDeliveryDays: $data['carrier_delivery_days'],
            manufacturingLeadTimeDays: $data['manufacturing_lead_time_days'],
            totalEstimatedDays: $data['total_estimated_days'],
            iconUrl: $data['icon_url'],
            metadata: $data['metadata'],
        );
    }

    /**
     * Converte o DTO em formato de array para serialização em JSON na API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'service_name' => $this->serviceName,
            'carrier_name' => $this->carrierName,
            'price_in_cents' => $this->priceInCents,
            'price' => $this->price,
            'price_formatted' => 'R$ '.number_format($this->price, 2, ',', '.'),
            'carrier_delivery_days' => $this->carrierDeliveryDays,
            'manufacturing_lead_time_days' => $this->manufacturingLeadTimeDays,
            'total_estimated_days' => $this->totalEstimatedDays,
            'icon_url' => $this->iconUrl,
            'metadata' => $this->metadata,
        ];
    }
}
