<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conta;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M-26: confirmação do e-mail da conta de cliente.
 */
class EmailVerificationController extends Controller
{
    private const INVALID_LINK_MESSAGE = 'Este link de confirmação não vale mais. Peça outro na sua conta.';

    /**
     * Chamado pela loja com os dados do link do e-mail, já logada. Confirma só se a assinatura valer,
     * o link for desta conta e o hash bater com o e-mail. Exigir a sessão da própria conta impede que
     * alguém crie a conta com o e-mail de outra pessoa e espere ela clicar no link para ver os pedidos dela.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        if (! $request->hasValidSignature()) {
            return $this->invalidLink();
        }

        if ((string) $customer->getKey() !== $id) {
            return response()->json([
                'code' => 'verification_other_account',
                'message' => 'Este link é de outra conta. Entre com o e-mail que recebeu o link.',
            ], 403);
        }

        // O e-mail mudou desde o envio (não há troca de e-mail hoje, mas o hash garante).
        if (! hash_equals(sha1($customer->getEmailForVerification()), $hash)) {
            return $this->invalidLink();
        }

        if (! $customer->hasVerifiedEmail()) {
            $customer->markEmailAsVerified();
        }

        return response()->json(['customer' => new CustomerResource($customer)]);
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        if ($customer->hasVerifiedEmail()) {
            return response()->json(['message' => 'Seu e-mail já está confirmado. 🐮'], 200);
        }

        $customer->sendEmailVerificationNotification();

        return response()->json(['message' => 'Enviamos um novo link de confirmação para o seu e-mail.'], 202);
    }

    private function invalidLink(): JsonResponse
    {
        return response()->json(['code' => 'verification_link_invalid', 'message' => self::INVALID_LINK_MESSAGE], 403);
    }
}
