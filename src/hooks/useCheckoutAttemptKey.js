import { useCallback, useRef } from 'react';

/** UUID v4 (crypto.randomUUID exige contexto seguro; o fallback cobre http em rede local de dev). */
const newUuid = () => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

/** Assinatura da tentativa: o payload sem card_token (de uso único, muda a cada tokenização). */
const attemptSignature = (payload) =>
  JSON.stringify(payload, (key, value) => (key === 'card_token' ? undefined : value));

/**
 * Idempotency-Key de uma tentativa de compra (header do POST /api/checkout).
 * - Mesmo carrinho, endereço, frete e forma de pagamento → mesma chave (duplo clique e retries
 *   de rede não criam pedido nem cobrança duplicados).
 * - Qualquer mudança nesses dados → chave nova.
 * - rotate(): chave nova após um resultado definitivo (sucesso, recusa ou erro devolvido pelo servidor).
 */
export const useCheckoutAttemptKey = () => {
  const attemptRef = useRef({ signature: null, key: null });

  const keyFor = useCallback((payload) => {
    const signature = attemptSignature(payload);
    if (!attemptRef.current.key || attemptRef.current.signature !== signature) {
      attemptRef.current = { signature, key: newUuid() };
    }
    return attemptRef.current.key;
  }, []);

  const rotate = useCallback(() => {
    attemptRef.current = { signature: null, key: null };
  }, []);

  return { keyFor, rotate };
};
