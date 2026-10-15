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
use Illuminate\Validation\ValidationException;

/**
 * M-26: cadastro, login, logout e "quem sou eu" da conta de cliente (sessão em cookie, guard `customer`).
 */
class AccountController extends Controller
{
    private const INVALID_CREDENTIALS_MESSAGE = 'E-mail ou senha não conferem.';

    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        try {
            $customer = Customer::create([
                'name' => trim((string) $request->validated('name')),
                'email' => (string) $request->validated('email'),
                'password' => (string) $request->validated('password'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dois cadastros simultâneos com o mesmo e-mail: o UNIQUE decide, a resposta é a mesma da validação.
            throw ValidationException::withMessages(['email' => RegisterCustomerRequest::EMAIL_TAKEN_MESSAGE]);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $customer->sendEmailVerificationNotification();

        return response()->json(['customer' => new CustomerResource($customer)], 201);
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

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return response()->json(['customer' => new CustomerResource($customer)]);
    }
}
