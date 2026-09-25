<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrackOrderRequest;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OrderTrackingController extends Controller
{
    /**
     * Endpoint público para consulta do status de manufatura da encomenda (Anti-IDOR / LGPD).
     */
    public function __invoke(TrackOrderRequest $request): JsonResponse|OrderTrackingResource
    {
        $token = $request->input('tracking_token');
        $orderNumber = $request->input('order_number');
        $cpfPrefix = (string) $request->input('cpf_prefix', '');
        $email = (string) $request->input('email', '');

        // Registros internos (revisão de pagamento etc.) nunca são carregados na resposta pública
        $query = Order::with(['items', 'productionLogs' => fn ($logs) => $logs->public()]);

        // Opção 1: Busca pelo Token Criptográfico (UUIDv4)
        if (! empty($token)) {
            /** @var Order|null $order */
            $order = $query->where('tracking_token', $token)->first();
        } else {
            // Opção 2: Busca por Chave Composta (Número do Pedido + Fator de Autenticação)
            $sanitizedOrderNumber = strtoupper(trim((string) $orderNumber));
            /** @var Order|null $order */
            $order = $query->where('order_number', $sanitizedOrderNumber)->first();

            if ($order) {
                $isCpfValid = ! empty($cpfPrefix) && str_starts_with(preg_replace('/\D/', '', $order->customer_cpf), $cpfPrefix);
                $isEmailValid = ! empty($email) && strtolower(trim($order->customer_email)) === strtolower(trim($email));

                if (! $isCpfValid && ! $isEmailValid) {
                    Log::warning("Tentativa de enumeração IDOR bloqueada na consulta de encomenda #{$sanitizedOrderNumber}", [
                        'ip' => $request->ip(),
                    ]);

                    // Resposta genérica para mitigar User Enumeration Attacks
                    return response()->json([
                        'message' => 'Nenhuma encomenda localizada com a combinação de dados informada.',
                    ], 404);
                }
            }
        }

        if (! $order) {
            return response()->json([
                'message' => 'Nenhuma encomenda localizada com a combinação de dados informada.',
            ], 404);
        }

        return new OrderTrackingResource($order);
    }
}
