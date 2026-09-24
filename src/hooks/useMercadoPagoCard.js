import { useState, useEffect, useCallback } from 'react';
import { setupMercadoPago, getFriendlyCardErrorMessage } from '../services/mercadoPago';
import { transformToApiPayload } from '../schemas/checkoutSchema';
import { ShippingPriceChangedError, isShippingPriceChanged } from '../utils/shippingPriceChange';

const API_BASE = 'http://localhost:8000/api';

/**
 * Hook de Orquestração do Pagamento com Cartão de Crédito (PCI-DSS Compliance)
 * Comunica com o backend enviando estritamente os tokens de cartão gerados pelo Mercado Pago.
 */
export const useMercadoPagoCard = () => {
  const [isReady, setIsReady] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState(null);

  // Inicializa o SDK do Mercado Pago na montagem do hook
  useEffect(() => {
    const ready = setupMercadoPago();
    setIsReady(ready);
  }, []);

  const clearError = useCallback(() => {
    setError(null);
  }, []);

  /**
   * Processa o pagamento enviando o token gerado pelo Brick de Cartão para o backend.
   *
   * @param {Object} cardFormData - Dados tokenizados do Brick (token, payment_method_id, installments)
   * @param {Object} cardFormData - Dados tokenizados do Brick (token, payment_method_id, installments)
   * @param {Object} orderCustomerData - Dados do comprador e endereço de entrega validados
   * @param {Array} cartItems - Lista de itens do carrinho
   * @param {number} [shippingCostCents=0] - Custo do frete em centavos
   * @param {Object|null} [selectedShipping=null] - Opção de frete selecionada
   * @returns {Promise<Object>} Resposta da API com dados da encomenda
   */
  const processCardPayment = useCallback(
    async (cardFormData, orderCustomerData, cartItems, shippingCostCents = 0, selectedShipping = null) => {
      if (!cardFormData || !cardFormData.token) {
        const msg = 'Token de pagamento não gerado. Verifique os dados do cartão.';
        setError(msg);
        throw new Error(msg);
      }

      setIsSubmitting(true);
      setError(null);

      // Monta o payload seguro com zero dados sensíveis brutos
      const payload = transformToApiPayload(
        {
          ...orderCustomerData,
          paymentMethod: 'credit_card',
        },
        cartItems,
        shippingCostCents,
        {
          card_token: cardFormData.token,
          installments: Number(cardFormData.installments) || 1,
          payment_method_id: cardFormData.payment_method_id || 'credit_card',
        },
        selectedShipping
      );

      try {
        const response = await fetch(`${API_BASE}/checkout`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(payload),
        });

        const result = await response.json();

        if (isShippingPriceChanged(response.status, result)) {
          // Frete mudou no servidor: nenhum pedido/cobrança foi criado; o modal pede nova confirmação.
          throw new ShippingPriceChangedError(result);
        }

        if (!response.ok) {
          const friendlyMessage = getFriendlyCardErrorMessage(
            result.status_detail || result.payment?.status_detail || result.message
          );
          setError(friendlyMessage);
          throw new Error(friendlyMessage);
        }

        // Verifica se o pagamento foi recusado pelo gateway no retorno
        if (result.payment?.status === 'rejected') {
          const friendlyMessage = getFriendlyCardErrorMessage(result.payment.status_detail);
          setError(friendlyMessage);
          throw new Error(friendlyMessage);
        }

        return result;
      } catch (err) {
        if (err instanceof ShippingPriceChangedError) {
          throw err;
        }
        if (!error) {
          const friendlyMsg = err.message || 'Falha ao autorizar pagamento com cartão.';
          setError(friendlyMsg);
        }
        throw err;
      } finally {
        setIsSubmitting(false);
      }
    },
    [error]
  );

  return {
    isReady,
    isSubmitting,
    error,
    processCardPayment,
    clearError,
  };
};
