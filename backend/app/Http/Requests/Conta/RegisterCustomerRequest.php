<?php

declare(strict_types=1);

namespace App\Http\Requests\Conta;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterCustomerRequest extends FormRequest
{
    public const PASSWORD_MESSAGE = 'A senha precisa ter de 8 a 72 caracteres, com letras e números.';

    public const BLOCKED_DOMAIN_MESSAGE = 'Não foi possível criar a conta com este e-mail. Use outro endereço.';

    /** Domínio da loja: ninguém cria conta de cliente com ele (ex.: a antiga usuária de demonstração). */
    private const BLOCKED_DOMAIN = 'mimoolab.com.br';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de senha compartilhadas com a troca de senha.
     *
     * @return array<int, string>
     */
    public static function passwordRules(): array
    {
        return ['required', 'string', 'min:8', 'max:72', 'regex:/\pL/u', 'regex:/\d/', 'confirmed'];
    }

    /**
     * @return array<string, string>
     */
    public static function passwordMessages(): array
    {
        return [
            'password.required' => self::PASSWORD_MESSAGE,
            'password.string' => self::PASSWORD_MESSAGE,
            'password.min' => self::PASSWORD_MESSAGE,
            'password.max' => self::PASSWORD_MESSAGE,
            'password.regex' => self::PASSWORD_MESSAGE,
            'password.confirmed' => 'A confirmação não é igual à senha.',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => self::passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Conta pra gente o seu nome.',
            'name.string' => 'Conta pra gente o seu nome.',
            'name.min' => 'O nome precisa ter pelo menos 3 letras.',
            'name.max' => 'O nome pode ter até 150 caracteres.',
            'email.required' => 'Informe um e-mail válido.',
            'email.string' => 'Informe um e-mail válido.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail pode ter até 150 caracteres.',
        ] + self::passwordMessages();
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email')) {
                    return;
                }

                $email = Customer::normalizeEmail((string) $this->input('email'));
                $domain = substr((string) strrchr($email, '@'), 1);

                // M-44: e-mail que já tem conta não dá erro aqui (o AccountController responde igual nos dois casos).
                if ($domain === self::BLOCKED_DOMAIN || str_ends_with($domain, '.'.self::BLOCKED_DOMAIN)) {
                    $validator->errors()->add('email', self::BLOCKED_DOMAIN_MESSAGE);
                }
            },
        ];
    }
}
