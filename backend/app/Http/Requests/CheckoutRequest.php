<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MaterialType;
use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalização pré-validação para aceitar tanto payloads com nós aninhados (shipping, payment)
     * quanto o payload consolidado do frontend (shipping_address, shipping_service, payment_method, etc.).
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        // 1. shipping_cost_cents só é lido da raiz (valor exibido ao cliente, usado apenas para
        //    detectar divergência); o frete cobrado é recalculado no servidor a partir de shipping_service.

        // 2. Mapeia shipping_address para shipping caso enviado no formato consolidado
        if (isset($input['shipping_address']) && is_array($input['shipping_address'])) {
            $shippingAddress = $input['shipping_address'];
            $shipping = $input['shipping'] ?? [];

            $shipping['zip_code'] = $shipping['zip_code'] ?? $shippingAddress['cep'] ?? $shippingAddress['zip_code'] ?? null;
            $shipping['street'] = $shipping['street'] ?? $shippingAddress['street'] ?? null;
            $shipping['number'] = $shipping['number'] ?? $shippingAddress['number'] ?? null;
            $shipping['complement'] = $shipping['complement'] ?? $shippingAddress['complement'] ?? null;
            $shipping['neighborhood'] = $shipping['neighborhood'] ?? $shippingAddress['neighborhood'] ?? null;
            $shipping['city'] = $shipping['city'] ?? $shippingAddress['city'] ?? null;
            $shipping['state'] = $shipping['state'] ?? $shippingAddress['state'] ?? null;
            $shipping['service_name'] = $shipping['service_name'] ?? $input['shipping_service'] ?? 'PAC';

            $input['shipping'] = $shipping;
        }

        // 3. Mapeia payment_method, card_token, installments da raiz para payment
        if (isset($input['payment_method']) && (! isset($input['payment']) || ! isset($input['payment']['payment_method']))) {
            $payment = $input['payment'] ?? [];
            $payment['payment_method'] = $input['payment_method'];

            if (array_key_exists('card_token', $input)) {
                $payment['card_token'] = $input['card_token'];
            }
            if (array_key_exists('installments', $input)) {
                $payment['installments'] = $input['installments'];
            }
            if (array_key_exists('payment_method_id', $input)) {
                $payment['payment_method_id'] = $input['payment_method_id'];
            }

            $input['payment'] = $payment;
        }

        // 4. Limpa CPF, telefone e CEP antes de validar: as regras conferem só os dígitos e o
        //    validated() (lido pelo CheckoutOrderDTO) já sai limpo. Valor que não é texto fica como
        //    veio, para a regra recusar com a mensagem própria.
        if (isset($input['customer']) && is_array($input['customer'])) {
            foreach (['cpf', 'phone'] as $field) {
                if (isset($input['customer'][$field]) && is_string($input['customer'][$field])) {
                    $input['customer'][$field] = (string) preg_replace('/\D/', '', $input['customer'][$field]);
                }
            }
        }

        if (isset($input['shipping']) && is_array($input['shipping'])) {
            if (isset($input['shipping']['zip_code']) && is_string($input['shipping']['zip_code'])) {
                $input['shipping']['zip_code'] = (string) preg_replace('/\D/', '', $input['shipping']['zip_code']);
            }
            if (isset($input['shipping']['state']) && is_string($input['shipping']['state'])) {
                $input['shipping']['state'] = strtoupper($input['shipping']['state']);
            }
        }

        $this->replace($input);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Dados do Comprador
            'customer.name' => ['required', 'string', 'min:3', 'max:150'],
            'customer.email' => ['required', 'email', 'max:150'],
            'customer.cpf' => ['required', 'string', new ValidCpf],
            'customer.phone' => ['required', 'string', 'regex:/^\d{10,11}$/'],

            // Endereço de Entrega
            'shipping.zip_code' => ['required', 'string', 'regex:/^\d{8}$/'],
            'shipping.street' => ['required', 'string', 'max:150'],
            'shipping.number' => ['required', 'string', 'max:30'],
            'shipping.complement' => ['nullable', 'string', 'max:100'],
            'shipping.neighborhood' => ['required', 'string', 'max:100'],
            'shipping.city' => ['required', 'string', 'max:100'],
            'shipping.state' => ['required', 'string', 'size:2'],
            'shipping.shipping_cost_cents' => ['nullable', 'integer', 'min:0'],
            'shipping.cost_in_cents' => ['nullable', 'integer', 'min:0'],
            'shipping_service' => ['required', 'string', 'max:30'],
            'shipping_cost_cents' => ['nullable', 'integer', 'min:0'],

            // Lista de Itens com Validação Estrita de Material e UUIDs
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.color_id' => ['required', 'uuid', 'exists:colors,id'],
            'items.*.size_id' => ['nullable', 'uuid', 'exists:sizes,id'],
            'items.*.material' => ['required', 'string', new Enum(MaterialType::class)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],

            // Método e Dados de Pagamento (PCI-DSS: Apenas tokens do Checkout Bricks)
            'payment.payment_method' => ['required', 'string', Rule::in(['pix', 'credit_card'])],
            'payment.card_token' => ['required_if:payment.payment_method,credit_card', 'nullable', 'string'],
            'payment.installments' => ['required_if:payment.payment_method,credit_card', 'nullable', 'integer', 'min:1', 'max:12'],
            'payment.payment_method_id' => ['required_if:payment.payment_method,credit_card', 'nullable', 'string'],
        ];
    }

    /**
     * Mensagens de validação em português.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer.name.required' => 'O nome completo do comprador é obrigatório.',
            'customer.email.required' => 'O e-mail para envio do rastreio é obrigatório.',
            'customer.email.email' => 'Informe um endereço de e-mail válido.',
            'customer.cpf.required' => 'O CPF é obrigatório para emissão da nota fiscal.',
            'customer.phone.required' => 'O telefone/WhatsApp é obrigatório.',
            'customer.phone.regex' => 'Informe um telefone/celular com DDD válido (10 ou 11 dígitos).',
            'shipping.zip_code.required' => 'O CEP de entrega é obrigatório.',
            'shipping.zip_code.regex' => 'O CEP deve conter exatamente 8 dígitos numéricos.',
            'shipping.street.required' => 'A rua/logradouro de entrega é obrigatória.',
            'shipping.number.required' => 'O número do imóvel é obrigatório.',
            'shipping.neighborhood.required' => 'O bairro de entrega é obrigatório.',
            'shipping.city.required' => 'A cidade de entrega é obrigatória.',
            'shipping.state.required' => 'O estado (UF) é obrigatório.',
            'shipping_service.required' => 'Escolha uma opção de frete para continuar.',
            'shipping_service.string' => 'A opção de frete informada é inválida.',
            'shipping_service.max' => 'A opção de frete informada é inválida.',
            'shipping_cost_cents.integer' => 'O valor do frete deve ser um valor inteiro em centavos.',
            'shipping_cost_cents.min' => 'O frete não pode ser negativo.',
            'items.required' => 'A sacola de encomendas não pode estar vazia.',
            'items.min' => 'Adicione pelo menos um item à sacola para finalizar a compra.',
            'items.*.product_id.required' => 'O identificador do produto é obrigatório.',
            'items.*.product_id.uuid' => 'O identificador do produto deve ser um UUID válido.',
            'items.*.product_id.exists' => 'Um dos produtos informados não existe no catálogo.',
            'items.*.color_id.required' => 'A cor do filamento é obrigatória para cada peça.',
            'items.*.color_id.uuid' => 'O identificador da cor deve ser um UUID válido.',
            'items.*.color_id.exists' => 'A cor selecionada não foi encontrada no catálogo.',
            'items.*.size_id.uuid' => 'O identificador do tamanho deve ser um UUID válido.',
            'items.*.size_id.exists' => 'A variação de tamanho selecionada não foi encontrada.',
            'items.*.material.required' => 'O material de impressão 3D (ex: PLA, PETG, ABS, TPU) é obrigatório.',
            'items.*.quantity.required' => 'A quantidade de cada item é obrigatória.',
            'items.*.quantity.min' => 'A quantidade mínima por peça é de 1 unidade.',
            'items.*.quantity.max' => 'A quantidade máxima por item na mesma encomenda é de 50 unidades.',
            'payment.payment_method.required' => 'Selecione uma forma de pagamento válida (pix ou credit_card).',
            'payment.payment_method.in' => 'Forma de pagamento inválida.',
        ];
    }
}
