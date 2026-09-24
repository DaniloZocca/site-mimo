import { z } from 'zod';
import { validateCPF, cleanNonDigits } from '../utils/validators';

/**
 * Esquema de Validação Estrito para o Checkout MIMOO LAB (Zod)
 * Assegura integridade e conformidade antes do envio para a API Laravel.
 */
export const checkoutFormSchema = z.object({
  // Dados do Comprador
  fullName: z
    .string()
    .trim()
    .min(3, 'O nome deve ter pelo menos 3 caracteres.')
    .max(100, 'O nome não pode ultrapassar 100 caracteres.')
    .refine((val) => /\S+\s+\S+/.test(val), {
      message: 'Informe seu nome completo (nome e sobrenome).',
    }),

  email: z
    .string()
    .trim()
    .toLowerCase()
    .min(1, 'O e-mail é obrigatório para envio do rastreio 3D.')
    .email('Informe um endereço de e-mail válido.'),

  cpf: z
    .string()
    .trim()
    .min(1, 'O CPF é obrigatório para emissão da nota e garantia.')
    .refine((val) => validateCPF(val), {
      message: 'CPF inválido. Verifique os números informados.',
    }),

  phone: z
    .string()
    .trim()
    .min(1, 'O telefone/WhatsApp é obrigatório para atualizações do pedido.')
    .refine(
      (val) => {
        const digits = cleanNonDigits(val);
        return digits.length === 10 || digits.length === 11;
      },
      {
        message: 'Informe um telefone/celular com DDD válido (10 ou 11 dígitos).',
      }
    ),

  // Endereço de Entrega
  cep: z
    .string()
    .trim()
    .min(1, 'O CEP é obrigatório.')
    .refine((val) => cleanNonDigits(val).length === 8, {
      message: 'O CEP deve conter exatamente 8 dígitos numéricos.',
    }),

  street: z
    .string()
    .trim()
    .min(2, 'O logradouro/rua é obrigatório.')
    .max(150, 'O logradouro não pode exceder 150 caracteres.'),

  number: z
    .string()
    .trim()
    .min(1, 'O número do imóvel é obrigatório.')
    .max(30, 'Número muito longo.'),

  complement: z
    .string()
    .trim()
    .max(100, 'O complemento não pode exceder 100 caracteres.')
    .optional()
    .nullable()
    .or(z.literal('')),

  neighborhood: z
    .string()
    .trim()
    .min(2, 'O bairro é obrigatório.')
    .max(100, 'O bairro não pode exceder 100 caracteres.'),

  city: z
    .string()
    .trim()
    .min(2, 'A cidade é obrigatória.')
    .max(100, 'A cidade não pode exceder 100 caracteres.'),

  state: z
    .string()
    .trim()
    .toUpperCase()
    .length(2, 'A UF deve ter exatamente 2 letras (ex: SP, RJ, PR).')
    .regex(/^[A-Z]{2}$/, 'UF inválida.'),

  // Método de Pagamento
  paymentMethod: z.enum(['pix', 'credit_card'], {
    errorMap: () => ({
      message: 'Selecione uma forma de pagamento válida (PIX ou Cartão).',
    }),
  }),

  // Consentimento LGPD
  lgpdConsent: z
    .boolean({
      required_error: 'O consentimento dos termos é obrigatório.',
    })
    .refine((val) => val === true, {
      message:
        'Você deve concordar com os termos de fabricação sob encomenda e privacidade (LGPD) para prosseguir.',
    }),
});

/**
 * Transforma os dados validados do formulário no payload JSON estrito esperado pelo backend Laravel (POST /api/checkout).
 * Suporta o contrato consolidado do Submódulo F1.2 com Zero-Trust Client.
 * 
 * @param {z.infer<typeof checkoutFormSchema>} formData
 * @param {Array} cartItems
 * @param {number} [shippingCostCents=0] ignorado: o frete enviado vem de selectedShipping (service_code + valor exibido)
 * @param {Object|null} [cardDetails=null] (apenas se paymentMethod for 'credit_card')
 * @param {Object|null} [selectedShipping=null]
 * @returns {Object}
 */
export const transformToApiPayload = (formData, cartItems, shippingCostCents = 0, cardDetails = null, selectedShipping = null) => {
  const cleanCpf = cleanNonDigits(formData.cpf);
  const cleanPhone = cleanNonDigits(formData.phone);
  const cleanCep = cleanNonDigits(formData.cep);

  // Frete: o backend recalcula pelo service_code; o valor exibido vai só para a checagem de divergência (409).
  const shippingService = selectedShipping?.service_code;
  const displayedShippingCents = Number.isInteger(selectedShipping?.price_in_cents)
    ? selectedShipping.price_in_cents
    : undefined;
  const paymentMethod = formData.paymentMethod;
  const cardToken = cardDetails?.card_token || cardDetails?.token || null;
  const installments = Number(cardDetails?.installments) || 1;

  // Validador de UUID para evitar enviar identificadores de string arbitrária no size_id
  const isValidUuid = (val) =>
    typeof val === 'string' &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(val);

  const formattedItems = cartItems.map((item) => {
    const rawSizeId = item.size?.id || item.selectedSize?.id || item.sizeId || item.size_id || null;
    return {
      product_id: item.productId || item.product?.id || item.id,
      color_id: item.color?.id || item.selectedColor?.id || item.colorId || item.color_id,
      size_id: isValidUuid(rawSizeId) ? rawSizeId : null,
      material: String(item.material?.id || item.selectedMaterial?.id || 'pla').toLowerCase(),
      quantity: Math.min(50, Math.max(1, Number(item.quantity) || 1)),
    };
  });

  const checkoutPayload = {
    customer: {
      name: formData.fullName.trim(),
      email: formData.email.trim().toLowerCase(),
      cpf: cleanCpf,
      phone: cleanPhone,
    },
    shipping_address: {
      cep: cleanCep,
      street: formData.street.trim(),
      number: formData.number.trim(),
      complement: formData.complement ? formData.complement.trim() : null,
      neighborhood: formData.neighborhood.trim(),
      city: formData.city.trim(),
      state: formData.state.trim().toUpperCase(),
    },
    shipping_service: shippingService,
    shipping_cost_cents: displayedShippingCents,
    payment_method: paymentMethod, // 'pix' ou 'credit_card'
    card_token: cardToken,
    installments: installments,
    items: formattedItems,

    // Nós aninhados legados para compatibilidade defensiva total
    shipping: {
      zip_code: cleanCep,
      street: formData.street.trim(),
      number: formData.number.trim(),
      complement: formData.complement ? formData.complement.trim() : null,
      neighborhood: formData.neighborhood.trim(),
      city: formData.city.trim(),
      state: formData.state.trim().toUpperCase(),
      service_name: selectedShipping?.service_name,
    },
    payment: {
      payment_method: paymentMethod,
      card_token: cardToken,
      installments: installments,
      payment_method_id: cardDetails?.payment_method_id || (paymentMethod === 'credit_card' ? 'credit_card' : null),
    },
  };

  return checkoutPayload;
};
