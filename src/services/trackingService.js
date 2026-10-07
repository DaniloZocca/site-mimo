import { cleanNonDigits } from '../utils/validators';
import { API_BASE } from '../config/api';

export class TrackingApiError extends Error {
  constructor(message, status = 500, isRateLimited = false) {
    super(message);
    this.name = 'TrackingApiError';
    this.status = status;
    this.isRateLimited = isRateLimited;
  }
}

/**
 * Consulta o rastreamento da encomenda no backend Laravel (POST /api/orders/track).
 * Implementa tratamento estrito de status HTTP (404, 422, 429, 500).
 *
 * @param {Object} params
 * @param {string} [params.trackingToken]
 * @param {string} [params.orderNumber]
 * @param {string} [params.identifier] (E-mail ou CPF)
 * @param {AbortSignal} [signal]
 * @returns {Promise<Object>}
 */
export const fetchOrderTracking = async (params, signal = null) => {
  let payload = {};

  if (params.trackingToken) {
    payload = {
      tracking_token: params.trackingToken.trim(),
    };
  } else if (params.orderNumber) {
    const rawIdentifier = (params.identifier || '').trim();
    const isEmail = rawIdentifier.includes('@');

    payload = {
      order_number: params.orderNumber.toUpperCase().trim(),
      ...(isEmail
        ? { email: rawIdentifier.toLowerCase() }
        : { cpf_prefix: cleanNonDigits(rawIdentifier).slice(0, 11) }),
    };
  } else {
    throw new TrackingApiError('Parâmetros de busca insuficientes.', 422);
  }

  try {
    const response = await fetch(`${API_BASE}/orders/track`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
      signal,
    });

    // 1. Tratamento de Rate Limit (HTTP 429 - Throttle Exceeded)
    if (response.status === 429) {
      throw new TrackingApiError(
        'Limite de consultas atingido por segurança (Anti-DDoS). Por favor, aguarde 1 minuto para pesquisar novamente.',
        429,
        true
      );
    }

    // 2. Tratamento de Não Encontrado (HTTP 404 - Not Found)
    if (response.status === 404) {
      throw new TrackingApiError(
        'Nenhuma encomenda foi localizada com a combinação de dados informada. Verifique o número e o e-mail/CPF.',
        404
      );
    }

    // 3. Tratamento de Validação Incorreta (HTTP 422 - Unprocessable Entity)
    if (response.status === 422) {
      const errorData = await response.json().catch(() => ({}));
      const message = errorData.message || 'Dados de consulta incompletos ou mal formatados.';
      throw new TrackingApiError(message, 422);
    }

    if (!response.ok) {
      throw new TrackingApiError(`Falha na comunicação com o servidor (HTTP ${response.status})`, response.status);
    }

    const result = await response.json();
    const orderData = result.data || result;

    // Normalização defensiva do payload de retorno
    return {
      orderNumber: orderData.order_number || '',
      trackingToken: orderData.tracking_token || '',
      customerFirstName: orderData.customer_first_name || 'Cliente',
      destination: orderData.destination || 'Brasil',
      status: orderData.status || 'pending_payment',
      statusLabel: orderData.status_label || 'Aguardando Pagamento',
      statusColor: orderData.status_color || 'amber',
      statusIcon: orderData.status_icon || 'clock',
      carrierTrackingCode: orderData.carrier_tracking_code || null,
      createdAt: orderData.created_at || '',
      paidAt: orderData.paid_at || null,
      items: Array.isArray(orderData.items) ? orderData.items : [],
      timeline: Array.isArray(orderData.timeline) ? orderData.timeline : [],
    };
  } catch (err) {
    if (err.name === 'AbortError') {
      throw err; // Repassa cancelamento intencional
    }

    if (err instanceof TrackingApiError) {
      throw err;
    }

    throw new TrackingApiError(
      'Não foi possível conectar ao servidor de rastreamento. Verifique sua conexão com a internet.',
      500
    );
  }
};
