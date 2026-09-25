/**
 * Utilitário Centralizado de Cálculo e Formatação de Preços - MIMOO LAB
 * 
 * Regra Fundamental:
 * Todos os cálculos financeiros são processados em centavos (números inteiros)
 * para eliminar erros de ponto flutuante do JavaScript (IEEE 754).
 */

const BRL_FORMATTER = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/**
 * Normaliza qualquer valor (reais ou centavos) para um inteiro estrito em centavos.
 * 
 * @param {number|string|null|undefined} value
 * @param {boolean} isCents
 * @returns {number}
 */
export const normalizeToCents = (value, isCents = true) => {
  if (value === null || value === undefined || isNaN(value)) {
    return 0;
  }

  const num = typeof value === 'string' 
    ? parseFloat(value.replace(/\./g, '').replace(',', '.')) 
    : Number(value);

  if (isNaN(num)) {
    return 0;
  }

  return isCents ? Math.round(num) : Math.round(num * 100);
};

/**
 * Converte centavos ou reais para string formatada no padrão oficial da moeda brasileira (R$ 80,00).
 * 
 * @param {number|string|null|undefined} centsOrReais
 * @param {boolean} isCents - Define se a entrada está em centavos (padrão: true)
 * @returns {string}
 */
export const formatMoney = (centsOrReais, isCents = true) => {
  const cents = normalizeToCents(centsOrReais, isCents);
  const reais = cents / 100;
  return BRL_FORMATTER.format(reais);
};

/**
 * Converte centavos para formato simplificado sem o símbolo "R$" (ex: "80,00").
 * 
 * @param {number|string|null|undefined} centsOrReais
 * @param {boolean} isCents
 * @returns {string}
 */
export const formatMoneyNumberOnly = (centsOrReais, isCents = true) => {
  const cents = normalizeToCents(centsOrReais, isCents);
  const reais = cents / 100;
  return reais.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

/**
 * Calcula a precificação integral do produto considerando modificadores de tamanho,
 * desconto à vista no PIX e parcelamento no cartão de crédito.
 * 
 * @param {Object} params
 * @param {number|string} params.basePriceCents - Preço base do produto em centavos
 * @param {number|string} [params.sizeModifierCents=0] - Adicional do tamanho/escala em centavos
 * @param {number} [params.quantity=1] - Quantidade de unidades
 * @param {number} [params.pixDiscountPercent=10] - Percentual de desconto no PIX (padrão: 10%)
 * @param {number} [params.maxInstallments=3] - Número máximo de parcelas sem juros (padrão: 3x)
 * 
 * @returns {{
 *   unitTotalCents: number,
 *   totalCartaoCents: number,
 *   totalPixCents: number,
 *   savingsCents: number,
 *   installmentValueCents: number,
 *   maxInstallments: number,
 *   pixDiscountPercent: number,
 *   unitTotalFormatted: string,
 *   totalCartaoFormatted: string,
 *   totalPixFormatted: string,
 *   installmentValueFormatted: string,
 *   savingsFormatted: string,
 *   installmentsText: string,
 *   pixPriceNumber: string,
 *   cardPriceNumber: string
 * }}
 */
export const calculateProductPricing = ({
  basePriceCents = 0,
  sizeModifierCents = 0,
  quantity = 1,
  pixDiscountPercent = 10,
  maxInstallments = 3,
}) => {
  const safeQuantity = Math.max(1, parseInt(quantity, 10) || 1);
  const safeBaseCents = normalizeToCents(basePriceCents, true);
  const safeModifierCents = normalizeToCents(sizeModifierCents, true);
  const safeDiscountPercent = Math.max(0, Math.min(100, Number(pixDiscountPercent) || 10));
  const safeInstallments = Math.max(1, parseInt(maxInstallments, 10) || 3);

  // 1. Total unitário com modificador de tamanho
  const unitTotalCents = Math.max(0, safeBaseCents + safeModifierCents);

  // 2. Total cheio no cartão (multiplicado pela quantidade)
  const totalCartaoCents = unitTotalCents * safeQuantity;

  // 3. Total com desconto no PIX: mesmo cálculo do backend, só com inteiros (desconto arredondado meio para cima)
  const totalPixCents = totalCartaoCents - Math.floor((totalCartaoCents * safeDiscountPercent + 50) / 100);

  // 4. Economia real proporcionada pelo PIX
  const savingsCents = Math.max(0, totalCartaoCents - totalPixCents);

  // 5. Valor de cada parcela sem juros
  const installmentValueCents = Math.round(totalCartaoCents / safeInstallments);

  return {
    unitTotalCents,
    totalCartaoCents,
    totalPixCents,
    savingsCents,
    installmentValueCents,
    maxInstallments: safeInstallments,
    pixDiscountPercent: safeDiscountPercent,

    // Strings formatadas para a interface
    unitTotalFormatted: formatMoney(unitTotalCents),
    totalCartaoFormatted: formatMoney(totalCartaoCents),
    totalPixFormatted: formatMoney(totalPixCents),
    installmentValueFormatted: formatMoney(installmentValueCents),
    savingsFormatted: formatMoney(savingsCents),
    installmentsText: `ou ${safeInstallments}x de ${formatMoney(installmentValueCents)} sem juros`,
    pixPriceNumber: formatMoneyNumberOnly(totalPixCents),
    cardPriceNumber: formatMoneyNumberOnly(totalCartaoCents),
  };
};

/**
 * Calcula os totais financeiros de múltiplos itens da sacola de compras.
 * 
 * @param {Array<Object>} cartItems
 * @param {number} [pixDiscountPercent=10]
 * @param {number} [shippingCents=0]
 * @returns {{
 *   subtotalCartaoCents: number,
 *   subtotalPixCents: number,
 *   shippingCents: number,
 *   grandTotalCartaoCents: number,
 *   grandTotalPixCents: number,
 *   savingsCents: number,
 *   subtotalFormatted: string,
 *   shippingFormatted: string,
 *   grandTotalCartaoFormatted: string,
 *   grandTotalPixFormatted: string,
 *   savingsFormatted: string
 * }}
 */
export const calculateCartTotals = (
  cartItems = [],
  pixDiscountPercent = 10,
  shippingCents = 0
) => {
  const safeShipping = normalizeToCents(shippingCents, true);

  const subtotalCartaoCents = cartItems.reduce((acc, item) => {
    const qty = parseInt(item.quantity, 10) || 1;
    // Itens do CartContext já trazem o unitário (base + tamanho) em centavos
    if (Number.isInteger(item.unitTotalCents)) {
      return acc + (item.unitTotalCents * qty);
    }
    const baseCents = item.product?.base_price_cents ?? normalizeToCents(item.product?.price, false);
    const sizeModifierCents = item.selectedSize?.price_modifier_cents ?? normalizeToCents(item.selectedSize?.addPrice, false);
    return acc + ((baseCents + sizeModifierCents) * qty);
  }, 0);

  // Mesmo cálculo do backend (OrderCreationService): desconto = round(subtotal × pct / 100), só nas peças.
  // Só inteiros: floor((x + 50) / 100) arredonda meio para cima sem float.
  const pixDiscountCents = Math.floor((subtotalCartaoCents * pixDiscountPercent + 50) / 100);
  const subtotalPixCents = subtotalCartaoCents - pixDiscountCents;

  const grandTotalCartaoCents = subtotalCartaoCents + safeShipping;
  const grandTotalPixCents = subtotalPixCents + safeShipping;
  const savingsCents = grandTotalCartaoCents - grandTotalPixCents;

  return {
    subtotalCartaoCents,
    subtotalPixCents,
    shippingCents: safeShipping,
    grandTotalCartaoCents,
    grandTotalPixCents,
    savingsCents,

    subtotalFormatted: formatMoney(subtotalCartaoCents),
    shippingFormatted: formatMoney(safeShipping),
    grandTotalCartaoFormatted: formatMoney(grandTotalCartaoCents),
    grandTotalPixFormatted: formatMoney(grandTotalPixCents),
    savingsFormatted: formatMoney(savingsCents),
  };
};
