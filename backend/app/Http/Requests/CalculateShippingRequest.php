<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateShippingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Endpoint público
    }

    /**
     * Sanitiza os dados da requisição antes da validação.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('destination_cep')) {
            $this->merge([
                'destination_cep' => preg_replace('/\D/', '', (string) $this->destination_cep),
            ]);
        }
    }

    /**
     * Regras rigorosas de validação Zero-Trust para cotação de frete.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'destination_cep' => [
                'required',
                'string',
                'size:8',
                'regex:/^\d{8}$/',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.product_id' => [
                'required',
                'string',
                'uuid',
                'exists:products,id',
            ],
            'items.*.size_id' => [
                'nullable',
                'string',
                'uuid',
                'exists:sizes,id',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:50',
            ],
        ];
    }

    /**
     * Mensagens de erro amigáveis em português.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_cep.required'  => 'O CEP de destino é obrigatório.',
            'destination_cep.size'      => 'O CEP deve conter exatamente 8 dígitos numéricos.',
            'destination_cep.regex'     => 'Formato de CEP inválido.',
            'items.required'            => 'A lista de itens do pedido é obrigatória.',
            'items.min'                 => 'Informe ao menos um produto para calcular o frete.',
            'items.*.product_id.exists' => 'Um dos produtos informados não existe em nosso catálogo.',
            'items.*.product_id.uuid'   => 'Identificador de produto inválido.',
            'items.*.size_id.exists'    => 'A variação de tamanho selecionada não foi encontrada.',
            'items.*.quantity.min'      => 'A quantidade mínima por item é de 1 unidade.',
            'items.*.quantity.max'      => 'A quantidade máxima para cotação de frete por pacote é de 50 unidades.',
        ];
    }
}
