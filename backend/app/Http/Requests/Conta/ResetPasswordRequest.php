<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => RegisterCustomerRequest::passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Este link de nova senha não vale mais. Peça outro.',
            'token.string' => 'Este link de nova senha não vale mais. Peça outro.',
            'token.max' => 'Este link de nova senha não vale mais. Peça outro.',
            'email.required' => 'Informe o e-mail da sua conta.',
            'email.string' => 'Informe um e-mail válido.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'Informe um e-mail válido.',
        ] + RegisterCustomerRequest::passwordMessages();
    }
}
