<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha de "Meus pedidos" (M-26). Sem CPF, telefone, e-mail nem endereço: o detalhe abre pelo
 * rastreio que já existe (tracking_token), que mascara os dados pessoais.
 *
 * @mixin Order
 */
class CustomerOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'total_cents' => (int) $this->total_cents,
            'items_count' => (int) $this->items_count,
            'tracking_token' => $this->tracking_token,
        ];
    }
}
