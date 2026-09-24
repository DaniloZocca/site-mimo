/**
 * POST /api/checkout com Idempotency-Key e nova tentativa curta quando o servidor avisa que o
 * primeiro envio desta mesma tentativa ainda está em andamento (409 request_in_progress).
 */
export const REQUEST_IN_PROGRESS = 'request_in_progress';

/**
 * 202 do cartão aprovado com divergência: pedido em revisão manual. Resultado definitivo:
 * nunca entra no retry abaixo (que só repete 409 + request_in_progress) nem permite nova cobrança.
 */
export const PAYMENT_UNDER_REVIEW = 'payment_under_review';
export const IN_PROGRESS_RETRY_DELAY_MS = 2000;
export const IN_PROGRESS_MAX_RETRIES = 3;

export class CheckoutInProgressError extends Error {
  constructor(message) {
    super(
      message ||
        'Seu pedido ainda está sendo processado. Aguarde alguns instantes e confira em "Rastrear pedido" antes de tentar de novo.'
    );
    this.name = 'CheckoutInProgressError';
    this.code = REQUEST_IN_PROGRESS;
  }
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * @param {string} url
 * @param {Object} payload
 * @param {string} idempotencyKey
 * @returns {Promise<{response: Response, result: Object}>}
 */
export const postCheckout = async (url, payload, idempotencyKey) => {
  for (let attempt = 0; ; attempt += 1) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify(payload),
    });

    const result = await response.json().catch(() => ({}));

    if (response.status === 409 && result?.code === REQUEST_IN_PROGRESS) {
      if (attempt < IN_PROGRESS_MAX_RETRIES) {
        await wait(IN_PROGRESS_RETRY_DELAY_MS);
        continue;
      }
      throw new CheckoutInProgressError();
    }

    return { response, result };
  }
};
