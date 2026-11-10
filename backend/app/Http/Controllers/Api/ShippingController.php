<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateShippingRequest;
use App\Services\ShippingCalculationService;
use App\Support\ErrorReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShippingController extends Controller
{
    public const UNEXPECTED_ERROR_MESSAGE = 'Não foi possível calcular o frete para o CEP informado.';

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
            // M-46: o CEP já foi validado pelo request; aqui só chegam erros inesperados, cuja mensagem
            // pode ter SQL ou dados do pedido. Texto fixo com código para o suporte achar o log.
            $ref = ErrorReference::generate();
            Log::error('Erro inesperado ao calcular o frete.', ErrorReference::context($e, $ref));

            return response()->json([
                'success' => false,
                'message' => ErrorReference::appendTo(self::UNEXPECTED_ERROR_MESSAGE, $ref),
                'error_ref' => $ref,
            ], 422);
        }
    }
}
