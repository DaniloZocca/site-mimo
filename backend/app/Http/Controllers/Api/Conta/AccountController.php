<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Conta;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conta\LoginCustomerRequest;
use App\Http\Requests\Conta\RegisterCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * M-26: cadastro, login, logout e "quem sou eu" da conta de cliente (sessão em cookie, guard `customer`).
 */
class AccountController extends Controller
{
    private const INVALID_CREDENTIALS_MESSAGE = 'E-mail ou senha não conferem.';

    public const REGISTERED_MESSAGE = 'Prontinho! Enviamos um e-mail para você confirmar o cadastro. 💜 Abra o link e entre com sua senha.';

    /**
     * M-44: a resposta é a mesma para e-mail novo e para e-mail que já tem conta (202, sem dados da conta e
     * sem login), para o cadastro não revelar quem é cliente. E-mail novo: cria a conta e manda "Confirme seu
     * e-mail". E-mail existente: não mexe na conta e avisa a dona dela. A pessoa entra depois pelo login.
     */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $email = Customer::normalizeEmail((string) $request->validated('email'));
        $password = (string) $request->validated('password');
        $existing = Customer::where('email', $email)->first();

        if ($existing !== null) {
            // Mesmo trabalho de hash do caso novo, para o tempo de resposta não denunciar qual caso foi.
            Hash::make($password);
            $existing->sendSignupAttemptNotification();

            return $this->registeredResponse();
        }

        try {
            $customer = Customer::create([
                'name' => trim((string) $request->validated('name')),
                'email' => $email,
                'password' => $password,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dois cadastros simultâneos com o mesmo e-mail: o UNIQUE decide e o segundo vira o caso "já tem conta".
            Customer::where('email', $email)->first()?->sendSignupAttemptNotification();

            return $this->registeredResponse();
        }

        $customer->sendEmailVerificationNotification();

        return $this->registeredResponse();
    }

    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $credentials = [
            'email' => Customer::normalizeEmail((string) $request->validated('email')),
            'password' => (string) $request->validated('password'),
        ];

        if (! Auth::guard('customer')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => self::INVALID_CREDENTIALS_MESSAGE]);
        }

        $request->session()->regenerate();

        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        return response()->json(['customer' => new CustomerResource($customer)]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function registeredResponse(): JsonResponse
    {
        return response()->json(['message' => self::REGISTERED_MESSAGE], 202);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return response()->json(['customer' => new CustomerResource($customer)]);
    }
}
