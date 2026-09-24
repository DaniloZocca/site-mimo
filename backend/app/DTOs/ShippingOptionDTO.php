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
     * Converte o DTO em formato de array para serialização em JSON na API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_code'                 => $this->serviceCode,
            'service_name'                 => $this->serviceName,
            'carrier_name'                 => $this->carrierName,
            'price_in_cents'               => $this->priceInCents,
            'price'                        => $this->price,
            'price_formatted'              => 'R$ ' . number_format($this->price, 2, ',', '.'),
            'carrier_delivery_days'        => $this->carrierDeliveryDays,
            'manufacturing_lead_time_days' => $this->manufacturingLeadTimeDays,
            'total_estimated_days'         => $this->totalEstimatedDays,
            'icon_url'                     => $this->iconUrl,
            'metadata'                     => $this->metadata,
        ];
    }
}
