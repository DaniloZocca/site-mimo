<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderTrackingResource extends JsonResource
{
    /**
     * Serialização segura com Mascaramento LGPD e Prevenção de Exfiltração de Dados.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 1. Extração segura do primeiro nome (Privacidade LGPD)
        $nameParts = explode(' ', trim((string) $this->customer_name));
        $firstName = $nameParts[0] ?? 'Cliente';

        // 2. Destino protegido (Apenas Cidade/UF, sem número de residência ou logradouro)
        $shipping = is_array($this->shipping_address) ? $this->shipping_address : [];
        $destinationCity  = (string) ($shipping['city'] ?? 'Brasil');
        $destinationState = (string) ($shipping['state'] ?? 'BR');

        // 3. Formatação da lista de peças manufaturadas (sem dados fiscais ou preços unitários)
        $items = $this->items->map(function (OrderItem $item): array {
            return [
                'title'    => $item->product_title_snapshot,
                'color'    => $item->color_name_snapshot,
                'size'     => $item->size_name_snapshot ?: 'Padrão (12cm)',
                'material' => $item->material_name_snapshot ?: 'PLA Premium',
                'quantity' => $item->quantity,
            ];
        })->values()->toArray();

        // 4. Linha do tempo pública e cronológica da produção 3D
        $timeline = $this->productionLogs->sortBy('created_at')->map(function (ProductionLog $log): array {
            $statusEnum = OrderStatus::tryFrom($log->new_status);

            // Sanitiza notas internas removendo nomes de operadores
            $cleanNotes = preg_replace('/^\[.*?\]\s*/', '', (string) $log->notes);

            return [
                'status'      => $log->new_status,
                'label'       => $statusEnum?->getLabel() ?? ucfirst($log->new_status),
                'color'       => $statusEnum?->getColor() ?? 'gray',
                'description' => $cleanNotes,
                'timestamp'   => $log->created_at?->format('d/m/Y H:i'),
                'iso_date'    => $log->created_at?->toIso8601String(),
            ];
        })->values()->toArray();

        return [
            'order_number'            => $this->order_number,
            'tracking_token'          => $this->tracking_token,
            'customer_first_name'     => $firstName,
            'destination'             => "{$destinationCity} - {$destinationState}",
            'status'                  => $this->status->value,
            'status_label'            => $this->status->getLabel(),
            'status_color'            => $this->status->getColor(),
            'status_icon'             => $this->status->getIcon(),
            'carrier_tracking_code'   => $this->carrier_tracking_code,
            'created_at'              => $this->created_at?->format('d/m/Y H:i'),
            'paid_at'                 => $this->paid_at?->format('d/m/Y H:i'),
            'items'                   => $items,
            'timeline'                => $timeline,
        ];
    }
}
