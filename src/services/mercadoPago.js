import { initMercadoPago } from '@mercadopago/sdk-react';

let isInitialized = false;

/**
 * Mapa de Mensagens de Recusa do Mercado Pago traduzidas para português amigável.
 * Em conformidade com as diretrizes de UX e prevenção de exposição de dados de segurança.
 */
export const CARD_ERROR_MESSAGES = {
  cc_rejected_insufficient_amount: 'Saldo insuficiente no cartão de crédito.',
  cc_rejected_bad_filled_security_code: 'Código de segurança (CVV) incorreto.',
  cc_rejected_bad_filled_date: 'Data de validade do cartão incorreta.',
  cc_rejected_bad_filled_other: 'Dados do cartão incorretos. Verifique e tente novamente.',
  cc_rejected_call_for_authorize:
    'Pagamento não autorizado pelo banco emissor. Entre em contato com a operadora do seu cartão.',
  cc_rejected_card_disabled: 'O cartão informado está desabilitado ou bloqueado.',
  cc_rejected_duplicated_payment: 'Pagamento duplicado identificado para esta encomenda.',
  cc_rejected_high_risk: 'Transação recusada por políticas de segurança do banco emissor.',
  cc_rejected_max_attempts: 'Limite de tentativas excedido no cartão. Tente novamente mais tarde ou use o PIX.',
  cc_rejected_blacklist: 'Não foi possível processar o pagamento com este cartão.',
};

/**
 * Retorna uma mensagem de erro amigável com base no status_detail do gateway.
 * @param {string} statusDetail
 * @returns {string}
 */
export const getFriendlyCardErrorMessage = (statusDetail) => {
  if (!statusDetail) {
    return 'Não foi possível autorizar o pagamento com cartão de crédito. Verifique os dados digitados ou utilize o PIX.';
  }
  return (
    CARD_ERROR_MESSAGES[statusDetail] ||
    'O pagamento com cartão não pôde ser autorizado. Verifique os dados ou utilize o PIX com aprovação imediata.'
  );
};

/**
 * Inicialização Idempotente do SDK do Mercado Pago no Frontend (PCI-DSS SAQ A).
 * Utiliza estritamente a chave pública (Public Key).
 *
 * @returns {boolean}
 */
export const setupMercadoPago = () => {
  if (isInitialized) {
    return true;
  }

  const publicKey =
    import.meta.env.VITE_MERCADOPAGO_PUBLIC_KEY ||
    import.meta.env.NEXT_PUBLIC_MERCADOPAGO_PUBLIC_KEY ||
    'TEST-b26a6a4e-39ff-411a-8bb4-099a9b7405be';

  try {
    initMercadoPago(publicKey, {
      locale: 'pt-BR',
    });
    isInitialized = true;
    return true;
  } catch (err) {
    console.error('Falha ao inicializar o SDK do Mercado Pago:', err);
    return false;
  }
};
