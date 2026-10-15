<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;

class LoginCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe o seu e-mail.',
            'email.string' => 'Informe um e-mail válido.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'Informe um e-mail válido.',
            'password.required' => 'Informe a sua senha.',
            'password.string' => 'Informe a sua senha.',
            'password.max' => 'E-mail ou senha não conferem.',
            'remember.boolean' => 'Valor inválido para "lembrar de mim".',
        ];
    }
}
