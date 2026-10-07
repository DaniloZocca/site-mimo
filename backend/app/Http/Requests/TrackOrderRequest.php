<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TrackOrderRequest extends FormRequest
{
    private const FULL_CPF_MESSAGE = 'Informe o CPF completo (11 números) ou o e-mail da compra.';

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
            // Opção 1: Acesso direto via Token Único Criptográfico (UUIDv4)
            'tracking_token' => ['nullable', 'uuid'],

            // Opção 2: Acesso por chave composta (Número do Pedido + CPF completo/E-mail)
            // O nome cpf_prefix é mantido por compatibilidade com o front; o valor precisa ter os 11 dígitos
            'order_number' => ['nullable', 'string', 'max:32'],
            'cpf_prefix' => ['nullable', 'string', 'max:14'],
            'email' => ['nullable', 'email', 'max:150'],
        ];
    }

    /**
     * Validação cruzada (Anti-IDOR): Exige ou o tracking_token OU a chave composta (order_number + CPF/E-mail).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $token = $this->input('tracking_token');
                $orderNumber = $this->input('order_number');
                $cpfPrefix = $this->input('cpf_prefix');
                $email = $this->input('email');

                if (empty($token) && empty($orderNumber)) {
                    $validator->errors()->add('identifier', 'Informe o token de rastreio ou o número da encomenda.');

                    return;
                }

                if (! empty($orderNumber) && empty($token)) {
                    if (empty($cpfPrefix) && empty($email)) {
                        $validator->errors()->add('auth_factor', self::FULL_CPF_MESSAGE);

                        return;
                    }

                    // Só o CPF completo autentica: prefixo (mesmo de 1 dígito) não basta (LGPD / anti-IDOR)
                    if (! empty($cpfPrefix) && strlen((string) preg_replace('/\D/', '', (string) $cpfPrefix)) !== 11) {
                        $validator->errors()->add('cpf_prefix', self::FULL_CPF_MESSAGE);
                    }
                }
            },
        ];
    }

    /**
     * Sanitização de entradas.
     */
    protected function passedValidation(): void
    {
        if ($this->has('cpf_prefix')) {
            $sanitizedCpf = preg_replace('/\D/', '', (string) $this->input('cpf_prefix'));
            $this->merge(['cpf_prefix' => $sanitizedCpf]);
        }
    }
}
