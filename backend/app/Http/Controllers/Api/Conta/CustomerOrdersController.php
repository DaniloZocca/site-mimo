<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conta;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * M-26: "Meus pedidos". Lista os pedidos gravados com o e-mail da conta (caixa e espaços ignorados),
 * inclusive os feitos antes de criar a conta, e só depois de o e-mail ser confirmado:
 * sem a confirmação, qualquer pessoa poderia criar conta com o e-mail de outra e ver os pedidos dela.
 */
class CustomerOrdersController extends Controller
{
    private const PER_PAGE = 20;

    public function __invoke(Request $request): JsonResponse|AnonymousResourceCollection
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        if (! $customer->hasVerifiedEmail()) {
            return response()->json([
                'code' => 'email_not_verified',
                'message' => 'Confirme seu e-mail para ver seus pedidos. Enviamos o link quando você criou a conta.',
            ], 403);
        }

        $orders = Order::query()
            ->whereRaw('lower(trim(customer_email)) = ?', [$customer->email])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE);

        return CustomerOrderResource::collection($orders);
    }
}
