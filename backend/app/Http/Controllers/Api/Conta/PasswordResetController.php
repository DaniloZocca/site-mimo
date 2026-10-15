<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conta\ForgotPasswordRequest;
use App\Http\Requests\Conta\ResetPasswordRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M-26: "Esqueci minha senha" e troca de senha pelo link do e-mail (broker `customers`).
 */
class PasswordResetController extends Controller
{
    private const INVALID_LINK_MESSAGE = 'Este link de nova senha não vale mais. Peça outro.';

    /**
     * Resposta igual para e-mail com e sem conta (e para o limite do broker), para não revelar quem é cliente.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('customers')->sendResetLink([
            'email' => Customer::normalizeEmail((string) $request->validated('email')),
        ]);

        return response()->json([
            'message' => 'Se este e-mail tiver conta, enviamos um link para criar uma nova senha. Confira a caixa de entrada e o spam.',
        ], 202);
    }

    /**
     * Troca a senha e gera um novo remember_token. As outras sessões caem no próximo acesso
     * (middleware auth.session compara o hash da senha guardado na sessão).
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('customers')->reset(
            [
                'email' => Customer::normalizeEmail((string) $request->validated('email')),
                'token' => (string) $request->validated('token'),
                'password' => (string) $request->validated('password'),
            ],
            function (Customer $customer, string $password): void {
                $customer->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK_MESSAGE]);
        }

        return response()->json(['message' => 'Senha nova criada! Agora é só entrar. 🐮']);
    }
}
