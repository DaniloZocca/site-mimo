<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Size;
use Illuminate\Support\Collection;

class PackagingService
{
    /**
     * Dimensões mínimas regulamentares exigidas pelos Correios (Brasil).
     */
    private int $minHeightCm;
    private int $minWidthCm;
    private int $minLengthCm;
    private int $tareWeightGrams;
    private int $bubbleWrapGrams;

    public function __construct()
    {
        $config = config('services.shipping.packaging', []);

        $this->minHeightCm     = (int) ($config['min_height_cm'] ?? 6);
        $this->minWidthCm      = (int) ($config['min_width_cm'] ?? 11);
        $this->minLengthCm     = (int) ($config['min_length_cm'] ?? 16);
        $this->tareWeightGrams = (int) ($config['tare_weight_grams'] ?? 50);
        $this->bubbleWrapGrams = (int) ($config['bubble_wrap_grams'] ?? 30);
    }

    /**
     * Calcula o pacote ideal e a cubagem para o conjunto de peças 3D da encomenda.
     *
     * @param iterable $items Itens do carrinho ou DTOs
     * @return array<string, mixed>
     */
    public function calculatePackage(iterable $items): array
    {
        $totalItemsWeightGrams = 0;
        $totalPrintHours = 0;
        $totalQuantity = 0;

        $accumulatedHeight = 0;
        $maxWidth = $this->minWidthCm;
        $maxLength = $this->minLengthCm;

        foreach ($items as $item) {
            $quantity = is_array($item) ? (int) ($item['quantity'] ?? 1) : (int) ($item->quantity ?? 1);
            $totalQuantity += $quantity;

            // Identifica o peso e o tempo de impressão da peça/tamanho
            $itemWeight = $this->resolveItemWeight($item);
            $itemPrintHours = $this->resolveItemPrintHours($item);

            $totalItemsWeightGrams += ($itemWeight * $quantity);
            $totalPrintHours += ($itemPrintHours * $quantity);

            // Estimativa geométrica de empacotamento com proteção
            $itemDimensions = $this->resolveItemDimensions($item);
            $accumulatedHeight += ($itemDimensions['height'] * $quantity);
            $maxWidth = max($maxWidth, $itemDimensions['width']);
            $maxLength = max($maxLength, $itemDimensions['length']);
        }

        // Peso bruto final = peças + caixa de papelão reforçada + plástico bolha
        $totalWeightGrams = $totalItemsWeightGrams + $this->tareWeightGrams + $this->bubbleWrapGrams;
        $totalWeightKg = max(0.1, round($totalWeightGrams / 1000, 3));

        // Aplicação das regras de cubagem mínima dos Correios
        $finalHeight = max($this->minHeightCm, min(100, (int) ceil($accumulatedHeight * 0.7))); // Fator de aninhamento
        $finalWidth  = max($this->minWidthCm, min(100, $maxWidth));
        $finalLength = max($this->minLengthCm, min(100, $maxLength));

        // Garantir que a soma das dimensões não ultrapasse o limite dos Correios (200cm)
        if (($finalHeight + $finalWidth + $finalLength) > 200) {
            $finalHeight = 60;
            $finalWidth = 60;
            $finalLength = 60;
        }

        // Algoritmo de Lead Time de Manufatura 3D (dias úteis de produção)
        $manufacturingLeadDays = $this->calculateManufacturingLeadDays($totalPrintHours);

        return [
            'weight_kg'                => $totalWeightKg,
            'weight_grams'             => $totalWeightGrams,
            'height_cm'                => $finalHeight,
            'width_cm'                 => $finalWidth,
            'length_cm'                => $finalLength,
            'manufacturing_hours'      => max(1, $totalPrintHours),
            'manufacturing_lead_days'  => $manufacturingLeadDays,
            'items_count'              => $totalQuantity,
        ];
    }

    /**
     * Converte as horas estimadas de extrusão na fazenda 3D em dias úteis de esteira.
     */
    public function calculateManufacturingLeadDays(int $totalPrintHours): int
    {
        if ($totalPrintHours <= 0) {
            return 1; // Mínimo de 1 dia útil para separação e envio
        }

        if ($totalPrintHours <= 8) {
            return 1; // 1 turno de impressão
        }

        if ($totalPrintHours <= 18) {
            return 2; // Impressão longa ou múltiplas peças
        }

        if ($totalPrintHours <= 36) {
            return 3;
        }

        return (int) ceil($totalPrintHours / 12) + 1;
    }

    /**
     * Resolve o peso de um item (em gramas).
     */
    private function resolveItemWeight(mixed $item): int
    {
        if (is_array($item)) {
            if (isset($item['weight_grams']) && $item['weight_grams'] > 0) {
                return (int) $item['weight_grams'];
            }
            if (isset($item['size_id'])) {
                $size = Size::find($item['size_id']);
                if ($size && $size->weight_grams) {
                    return (int) $size->weight_grams;
                }
            }
            return 120; // Peso médio padrão de peça em PLA
        }

        if (isset($item->weight_grams) && $item->weight_grams > 0) {
            return (int) $item->weight_grams;
        }

        if (isset($item->size) && $item->size instanceof Size && $item->size->weight_grams) {
            return (int) $item->size->weight_grams;
        }

        return 120;
    }

    /**
     * Resolve as horas de impressão de um item.
     */
    private function resolveItemPrintHours(mixed $item): int
    {
        if (is_array($item)) {
            if (isset($item['print_time_hours']) && $item['print_time_hours'] > 0) {
                return (int) $item['print_time_hours'];
            }
            if (isset($item['product_id'])) {
                $product = Product::find($item['product_id']);
                if ($product && $product->print_time_hours) {
                    return (int) $product->print_time_hours;
                }
            }
            return 4; // Média de 4 horas por item
        }

        if (isset($item->print_time_hours) && $item->print_time_hours > 0) {
            return (int) $item->print_time_hours;
        }

        if (isset($item->product) && $item->product instanceof Product && $item->product->print_time_hours) {
            return (int) $item->product->print_time_hours;
        }

        return 4;
    }

    /**
     * Estima dimensões aproximadas de empacotamento com base na escala.
     *
     * @return array{height: int, width: int, length: int}
     */
    private function resolveItemDimensions(mixed $item): array
    {
        $sizeName = '';
        if (is_array($item)) {
            $sizeName = (string) ($item['size_name'] ?? $item['size'] ?? '');
        } elseif (isset($item->size_name_snapshot)) {
            $sizeName = (string) $item->size_name_snapshot;
        } elseif (isset($item->size->name)) {
            $sizeName = (string) $item->size->name;
        }

        $sizeName = strtoupper(trim($sizeName));

        return match ($sizeName) {
            'P', 'PEQUENO'    => ['height' => 6, 'width' => 8, 'length' => 10],
            'G', 'GRANDE'     => ['height' => 14, 'width' => 16, 'length' => 18],
            'GG', 'EXTRA'     => ['height' => 18, 'width' => 20, 'length' => 22],
            default           => ['height' => 10, 'width' => 12, 'length' => 14], // 'M'
        };
    }
}
