import { useState, useEffect, useRef, useCallback } from 'react';

const API_BASE = '/api';
const BASE_POLLING_INTERVAL_MS = 4000; // 4 segundos base
const MAX_BACKOFF_INTERVAL_MS = 20000; // Teto de 20s em caso de falhas de rede

/**
 * Hook de Monitoramento em Tempo Real do Pagamento (PIX / Cartão)
 * Implementa Polling Inteligente, Backoff Exponencial Anti-DDoS, e AbortController.
 *
 * @param {Object} params
 * @param {string|null} params.orderId
 * @param {string|null} params.trackingToken
 * @param {boolean} params.isPollingActive
 * @param {Function} [params.onPaymentApproved]
 * @param {Function} [params.onPaymentExpired]
 */
export const usePaymentStatus = ({
  orderId = null,
  trackingToken = null,
  isPollingActive = false,
  onPaymentApproved = null,
  onPaymentExpired = null,
}) => {
  const [orderData, setOrderData] = useState(null);
  const [status, setStatus] = useState('pending_payment');
  const [isApproved, setIsApproved] = useState(false);
  const [isPolling, setIsPolling] = useState(false);
  const [errorCount, setErrorCount] = useState(0);

  // Referências para gerenciamento de ciclo de vida e concorrência
  const timerRef = useRef(null);
  const abortControllerRef = useRef(null);
  const isApprovedRef = useRef(false);
  const consecutiveErrorsRef = useRef(0);

  // Armazena callbacks para evitar re-triggers desnecessários
  const onPaymentApprovedRef = useRef(onPaymentApproved);
  onPaymentApprovedRef.current = onPaymentApproved;

  const onPaymentExpiredRef = useRef(onPaymentExpired);
  onPaymentExpiredRef.current = onPaymentExpired;

  // Função atômica de verificação do status
  const checkStatus = useCallback(async () => {
    if (!trackingToken || isApprovedRef.current) return;

    // Aborta requisição de verificação anterior se ainda estiver em voo
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    const controller = new AbortController();
    abortControllerRef.current = controller;

    try {
      const response = await fetch(`${API_BASE}/orders/track`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          tracking_token: trackingToken,
        }),
        signal: controller.signal,
      });

      if (!response.ok) {
        // Erros de servidor (5xx) ativam o backoff para não sobrecarregar a API
        if (response.status >= 500) {
          consecutiveErrorsRef.current += 1;
          setErrorCount(consecutiveErrorsRef.current);
        }
        return;
      }

      // Sucesso na comunicação: reseta contador de erros
      consecutiveErrorsRef.current = 0;
      setErrorCount(0);

      const result = await response.json();
      const currentStatus = result.status || (result.data && result.data.status);
      const trackingData = result.data || result;

      setOrderData(trackingData);
      setStatus(currentStatus);

      // Se o status mudou de 'pending_payment' para qualquer estágio de produção ou aprovado
      if (currentStatus && currentStatus !== 'pending_payment') {
        if (currentStatus !== 'cancelled') {
          isApprovedRef.current = true;
          setIsApproved(true);
          setIsPolling(false);

          if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
          }

          if (typeof onPaymentApprovedRef.current === 'function') {
            onPaymentApprovedRef.current(trackingData);
          }
        } else {
          // Pedido cancelado / expirado
          setIsPolling(false);
          if (typeof onPaymentExpiredRef.current === 'function') {
            onPaymentExpiredRef.current(trackingData);
          }
        }
      }
    } catch (err) {
      if (err.name !== 'AbortError') {
        consecutiveErrorsRef.current += 1;
        setErrorCount(consecutiveErrorsRef.current);
      }
    } finally {
      abortControllerRef.current = null;
    }
  }, [trackingToken]);

  // Efeito principal do Polling com Backoff
  useEffect(() => {
    if (!isPollingActive || !trackingToken || isApproved) {
      setIsPolling(false);
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
      return;
    }

    setIsPolling(true);

    const scheduleNextCheck = () => {
      // Backoff Exponencial simples: aumenta intervalo se houver erros de rede consecutivos
      const delay = Math.min(
        MAX_BACKOFF_INTERVAL_MS,
        BASE_POLLING_INTERVAL_MS * Math.pow(1.4, consecutiveErrorsRef.current)
      );

      timerRef.current = setTimeout(async () => {
        if (!isApprovedRef.current) {
          await checkStatus();
          scheduleNextCheck();
        }
      }, delay);
    };

    // Dispara a primeira checagem imediatamente e agenda as próximas
    checkStatus();
    scheduleNextCheck();

    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
        abortControllerRef.current = null;
      }
    };
  }, [isPollingActive, trackingToken, isApproved, checkStatus]);

  // Função para disparo manual sob demanda (ex: botão "Já realizei o pagamento")
  const manualCheck = useCallback(() => {
    return checkStatus();
  }, [checkStatus]);

  return {
    orderData,
    status,
    isApproved,
    isPolling,
    consecutiveErrors: errorCount,
    manualCheck,
  };
};
