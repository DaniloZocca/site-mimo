<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ShippingOptionDTO;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShippingCalculationService
{
    private string $originCep;

    private string $melhorEnvioUrl;

    private string $melhorEnvioToken;

    public function __construct(
        private readonly PackagingService $packagingService
    ) {
        $this->originCep = (string) config('services.shipping.origin_cep', '01001000');
        $this->melhorEnvioUrl = (string) config('services.shipping.melhor_envio.url', 'https://sandbox.melhorenvio.com.br');
        $this->melhorEnvioToken = (string) config('services.shipping.melhor_envio.token', '');
    }

    /**
     * Calcula as opções de frete (PAC, SEDEX, etc.) com tempo total combinado de manufatura 3D.
     *
     * @param  string  $destinationCep  CEP de destino (8 dígitos)
     * @param  iterable  $items  Itens do pedido para cubagem
     * @return array<int, ShippingOptionDTO>
     */
    public function calculate(string $destinationCep, iterable $items): array
    {
        $cleanCep = preg_replace('/\D/', '', $destinationCep);

        if (strlen($cleanCep) !== 8) {
            throw new Exception('CEP de destino inválido. O CEP deve conter 8 dígitos numéricos.');
        }

        // 1. Calcula pacote volumétrico e lead time da esteira 3D
        $package = $this->packagingService->calculatePackage($items);

        // 2. Chave de cache inteligente baseada no CEP e cubagem (Validade: 6 horas)
        $cacheKey = 'shipping_rate:'.md5("{$cleanCep}:".json_encode($package));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($cleanCep, $package): array {
            // Se o token da API não estiver configurado, usa fallback regional local
            if (empty($this->melhorEnvioToken)) {
                Log::info("Cotação de frete para CEP {$cleanCep} calculada via Fallback Regional (Token do Melhor Envio não configurado).");

                return $this->calculateFallbackShipping($cleanCep, $package);
            }

            try {
                $response = Http::timeout(5)
                    ->retry(2, 100)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$this->melhorEnvioToken,
                        'User-Agent' => 'MIMOO LAB 3D Store (contato@mimoolab.com.br)',
                    ])
                    ->post(rtrim($this->melhorEnvioUrl, '/').'/api/v2/me/shipment/calculate', [
                        'from' => [
                            'postal_code' => $this->originCep,
                        ],
                        'to' => [
                            'postal_code' => $cleanCep,
                        ],
                        'package' => [
                            'height' => $package['height_cm'],
                            'width' => $package['width_cm'],
                            'length' => $package['length_cm'],
                            'weight' => $package['weight_kg'],
                        ],
                        'options' => [
                            'receipt' => false,
                            'own_hand' => false,
                        ],
                        'services' => '1,2', // 1 = PAC, 2 = SEDEX
                    ]);

                if (! $response->successful()) {
                    Log::warning("Falha na resposta da API Melhor Envio (HTTP {$response->status()}): {$response->body()}");

                    return $this->calculateFallbackShipping($cleanCep, $package);
                }

                $rates = $response->json();

                if (! is_array($rates) || empty($rates)) {
                    return $this->calculateFallbackShipping($cleanCep, $package);
                }

                $options = [];
                $manufacturingDays = $package['manufacturing_lead_days'];

                foreach ($rates as $rate) {
                    if (isset($rate['error']) && ! empty($rate['error'])) {
                        continue;
                    }

                    $priceFloat = (float) ($rate['price'] ?? $rate['custom_price'] ?? 0.0);
                    if ($priceFloat <= 0) {
                        continue;
                    }

                    $carrierDeliveryDays = (int) ($rate['delivery_time'] ?? $rate['custom_delivery_time'] ?? 3);
                    $totalDays = $carrierDeliveryDays + $manufacturingDays;
                    $priceInCents = (int) round($priceFloat * 100);

                    $serviceName = (string) ($rate['name'] ?? 'Padrão');
                    $serviceCode = strtolower(str_contains($serviceName, 'SEDEX') ? 'sedex' : 'pac');

                    $options[] = new ShippingOptionDTO(
                        serviceCode: $serviceCode,
                        serviceName: $serviceName,
                        carrierName: (string) ($rate['company']['name'] ?? 'Correios'),
                        priceInCents: $priceInCents,
                        price: $priceFloat,
                        carrierDeliveryDays: $carrierDeliveryDays,
                        manufacturingLeadTimeDays: $manufacturingDays,
                        totalEstimatedDays: $totalDays,
                        iconUrl: $rate['company']['picture'] ?? null,
                        metadata: [
                            'package' => $package,
                            'manufacturing_hours' => $package['manufacturing_hours'],
                        ]
                    );
                }

                return ! empty($options) ? $options : $this->calculateFallbackShipping($cleanCep, $package);

            } catch (Exception $e) {
                Log::error("Exceção ao cotar frete no Melhor Envio: {$e->getMessage()}");

                return $this->calculateFallbackShipping($cleanCep, $package);
            }
        });
    }

    /**
     * Recalcula as opções (mesmo caminho e mesmo cache da cotação pública) e devolve o serviço
     * escolhido no checkout junto com todas as opções, ou null se o serviço não estiver disponível.
     *
     * @param  iterable  $items  Itens no formato da cotação: [product_id, size_id, quantity]
     * @return array{option: ?ShippingOptionDTO, options: array<int, ShippingOptionDTO>}
     */
    public function findOption(string $destinationCep, iterable $items, string $serviceCode): array
    {
        $options = $this->calculate($destinationCep, $items);

        foreach ($options as $option) {
            if ($option->serviceCode === $serviceCode) {
                return ['option' => $option, 'options' => $options];
            }
        }

        return ['option' => null, 'options' => $options];
    }

    /**
     * Origem do preço de uma opção: 'fallback' (tabela regional) ou 'melhor_envio'.
     */
    public static function priceSource(ShippingOptionDTO $option): string
    {
        return ! empty($option->metadata['fallback']) ? 'fallback' : 'melhor_envio';
    }

    /**
     * Cotação de contingência baseada na faixa regional de CEP brasileira.
     * Garante que o checkout nunca quebre mesmo se as APIs externas estiverem fora do ar.
     *
     * @return array<int, ShippingOptionDTO>
     */
    public function calculateFallbackShipping(string $cleanCep, array $package): array
    {
        $manufacturingDays = $package['manufacturing_lead_days'];
        $cepPrefix = (int) substr($cleanCep, 0, 2);

        // Classificação por Macrorregiões dos Correios
        if ($cepPrefix >= 1 && $cepPrefix <= 19) {
            // Estado de São Paulo (Origem MIMOO LAB)
            $pacPrice = 14.90;
            $pacDays = 3;
            $sedexPrice = 22.90;
            $sedexDays = 1;
        } elseif ($cepPrefix >= 20 && $cepPrefix <= 39) {
            // Sudeste (RJ, MG, ES)
            $pacPrice = 22.90;
            $pacDays = 5;
            $sedexPrice = 36.90;
            $sedexDays = 2;
        } elseif ($cepPrefix >= 80 && $cepPrefix <= 99) {
            // Sul (PR, SC, RS)
            $pacPrice = 26.90;
            $pacDays = 6;
            $sedexPrice = 44.90;
            $sedexDays = 3;
        } elseif ($cepPrefix >= 70 && $cepPrefix <= 79) {
            // Centro-Oeste (DF, GO, MT, MS)
            $pacPrice = 31.90;
            $pacDays = 7;
            $sedexPrice = 52.90;
            $sedexDays = 3;
        } else {
            // Norte e Nordeste (BA, PE, CE, AM, PA, etc.)
            $pacPrice = 38.90;
            $pacDays = 9;
            $sedexPrice = 64.90;
            $sedexDays = 4;
        }

        return [
            new ShippingOptionDTO(
                serviceCode: 'pac',
                serviceName: 'PAC (Econômico)',
                carrierName: 'Correios',
                priceInCents: (int) round($pacPrice * 100),
                price: $pacPrice,
                carrierDeliveryDays: $pacDays,
                manufacturingLeadTimeDays: $manufacturingDays,
                totalEstimatedDays: $pacDays + $manufacturingDays,
                iconUrl: null,
                metadata: [
                    'package' => $package,
                    'manufacturing_hours' => $package['manufacturing_hours'],
                    'fallback' => true,
                ]
            ),
            new ShippingOptionDTO(
                serviceCode: 'sedex',
                serviceName: 'SEDEX (Expresso)',
                carrierName: 'Correios',
                priceInCents: (int) round($sedexPrice * 100),
                price: $sedexPrice,
                carrierDeliveryDays: $sedexDays,
                manufacturingLeadTimeDays: $manufacturingDays,
                totalEstimatedDays: $sedexDays + $manufacturingDays,
                iconUrl: null,
                metadata: [
                    'package' => $package,
                    'manufacturing_hours' => $package['manufacturing_hours'],
                    'fallback' => true,
                ]
            ),
        ];
    }
}
