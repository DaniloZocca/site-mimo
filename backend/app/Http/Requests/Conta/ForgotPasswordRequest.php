<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail da sua conta.',
            'email.string' => 'Informe um e-mail válido.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'Informe um e-mail válido.',
        ];
    }
}
