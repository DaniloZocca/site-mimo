/**
 * Contrato do 409 do POST /api/checkout quando o frete exibido diverge do recalculado no servidor.
 * O backend é a fonte da verdade: o front só atualiza a opção e pede nova confirmação ao cliente.
 */
export const SHIPPING_PRICE_CHANGED = 'shipping_price_changed';

export class ShippingPriceChangedError extends Error {
  constructor(data) {
    super(data?.message || 'O valor do frete mudou. Confira o novo valor e confirme o pedido de novo.');
    this.name = 'ShippingPriceChangedError';
    this.code = SHIPPING_PRICE_CHANGED;
    this.shippingService = data?.shipping_service ?? null;
    this.shippingCostCents = Number.isInteger(data?.shipping_cost_cents) ? data.shipping_cost_cents : null;
    this.shippingOptions = Array.isArray(data?.shipping_options) ? data.shipping_options : [];
  }
}

export const isShippingPriceChanged = (status, data) =>
  status === 409 && data?.code === SHIPPING_PRICE_CHANGED;
