<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conta;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * M-26: confirmação do e-mail da conta de cliente.
 */
class EmailVerificationController extends Controller
{
    /**
     * Link assinado do e-mail. Não exige login (a pessoa pode abrir em outro aparelho): a assinatura
     * e o hash do e-mail autenticam. Sempre devolve para a loja, nunca mostra erro da API.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $front = rtrim((string) config('app.frontend_url'), '/');

        $customer = $request->hasValidSignature() ? Customer::find($id) : null;

        if ($customer === null || ! hash_equals(sha1($customer->getEmailForVerification()), $hash)) {
            return redirect()->away($front.'/?conta=link-invalido');
        }

        if (! $customer->hasVerifiedEmail()) {
            $customer->markEmailAsVerified();
        }

        return redirect()->away($front.'/?conta=verificada');
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
}
