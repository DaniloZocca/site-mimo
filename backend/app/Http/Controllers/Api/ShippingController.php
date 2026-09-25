<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateShippingRequest;
use App\Services\ShippingCalculationService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ShippingController extends Controller
{
    /**
     * Cotação de frete pública com proteção LGPD e validação Zero-Trust de catálogo.
     */
    public function calculate(
        CalculateShippingRequest $request,
        ShippingCalculationService $shippingService
    ): JsonResponse {
        $destinationCep = (string) $request->validated('destination_cep');
        $items = $request->validated('items');

        try {
            $options = $shippingService->calculate(
                destinationCep: $destinationCep,
                items: $items
            );

            return response()->json([
                'success' => true,
                'destination_cep' => $destinationCep,
                'options' => array_map(fn ($option) => $option->toArray(), $options),
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível calcular o frete para o CEP informado.',
            ], 422);
        }
    }
}
