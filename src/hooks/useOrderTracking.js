import { useState, useEffect, useRef, useCallback } from 'react';
import { fetchOrderTracking, TrackingApiError } from '../services/trackingService';

/**
 * Hook de Rastreamento de Encomendas da MIMOO LAB
 * Suporta busca por Token Criptográfico (UUID), por Chave Composta, e leitura automática de URL (?token=...).
 */
export const useOrderTracking = () => {
  const [order, setOrder] = useState(null);
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [rateLimited, setRateLimited] = useState(false);

  const abortControllerRef = useRef(null);

  const resetState = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }
    setOrder(null);
    setLogs([]);
    setError(null);
    setLoading(false);
    setRateLimited(false);
  }, []);

  /**
   * Executa a busca através de parâmetros genéricos com cancelamento defensivo.
   */
  const executeTrack = useCallback(async (params) => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    const controller = new AbortController();
    abortControllerRef.current = controller;

    setLoading(true);
    setError(null);
    setRateLimited(false);

    try {
      const data = await fetchOrderTracking(params, controller.signal);

      setOrder(data);
      setLogs(data.timeline || []);
      setError(null);
      setRateLimited(false);
      return data;
    } catch (err) {
      if (err.name === 'AbortError') {
        return null;
      }

      if (err instanceof TrackingApiError && err.isRateLimited) {
        setRateLimited(true);
      }

      setError(err.message || 'Erro ao consultar encomenda.');
      setOrder(null);
      setLogs([]);
      return null;
    } finally {
      setLoading(false);
      abortControllerRef.current = null;
    }
  }, []);

  /**
   * Consulta pública por Token Criptográfico (UUIDv4).
   */
  const trackByToken = useCallback(
    (trackingToken) => {
      if (!trackingToken) return Promise.resolve(null);
      return executeTrack({ trackingToken });
    },
    [executeTrack]
  );

  /**
   * Consulta pública por Chave Composta (Número do Pedido + E-mail/CPF).
   */
  const trackByCredentials = useCallback(
    (orderNumber, identifier) => {
      if (!orderNumber || !identifier) return Promise.resolve(null);
      return executeTrack({ orderNumber, identifier });
    },
    [executeTrack]
  );

  // Leitura Automática de Parâmetros de URL (?token=UUID ou ?order=...&cpf=...)
  useEffect(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const tokenFromUrl = urlParams.get('token');
    const orderFromUrl = urlParams.get('order') || urlParams.get('order_number');
    const cpfFromUrl = urlParams.get('cpf');
    const emailFromUrl = urlParams.get('email');

    if (tokenFromUrl) {
      trackByToken(tokenFromUrl);
    } else if (orderFromUrl && (cpfFromUrl || emailFromUrl)) {
      trackByCredentials(orderFromUrl, cpfFromUrl || emailFromUrl);
    }

    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, [trackByToken, trackByCredentials]);

  return {
    order,
    logs,
    loading,
    error,
    rateLimited,
    trackByToken,
    trackByCredentials,
    resetState,
  };
};
