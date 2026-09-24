import { useState, useRef, useCallback, useEffect } from 'react';
import { cleanNonDigits } from '../utils/validators';

/**
 * Hook de Busca Resiliente de Endereço via ViaCEP
 * Possui AbortController para cancelamento de requisições obsoletas e timeout defensivo de 6s.
 */
export const useViaCep = () => {
  const [address, setAddress] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Referência para abortar requisições em trânsito
  const abortControllerRef = useRef(null);

  const resetAddress = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }
    setAddress(null);
    setError(null);
    setLoading(false);
  }, []);

  const fetchAddressByCep = useCallback(async (rawCep) => {
    const cleanCep = cleanNonDigits(rawCep);

    if (cleanCep.length !== 8) {
      setAddress(null);
      setError(null);
      return null;
    }

    // Cancela requisição anterior pendente se o usuário digitou outro CEP rapidamente
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    const controller = new AbortController();
    abortControllerRef.current = controller;

    setLoading(true);
    setError(null);

    // Timeout de 6 segundos para resiliência de rede móvel
    const timeoutId = setTimeout(() => {
      controller.abort();
    }, 6000);

    try {
      const response = await fetch(`https://viacep.com.br/ws/${cleanCep}/json/`, {
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
        },
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        throw new Error(`Falha no serviço ViaCEP (HTTP ${response.status})`);
      }

      const data = await response.json();

      // ViaCEP retorna { erro: true } quando o CEP não é localizado
      if (data.erro) {
        setError('CEP não localizado. Por favor, preencha o logradouro e bairro manualmente.');
        setAddress(null);
        return null;
      }

      const formattedAddress = {
        cep: cleanCep,
        street: data.logradouro || '',
        neighborhood: data.bairro || '',
        city: data.localidade || '',
        state: (data.uf || '').toUpperCase(),
      };

      setAddress(formattedAddress);
      setError(null);
      return formattedAddress;
    } catch (err) {
      clearTimeout(timeoutId);

      // Se foi cancelado intencionalmente por uma nova digitação, ignora
      if (err.name === 'AbortError') {
        // Se foi timeout ou abort manual
        if (controller.signal.aborted) {
          setError('Tempo limite atingido ao consultar CEP. Você pode preencher os campos manualmente.');
        }
        return null;
      }

      setError('Não foi possível buscar o endereço automaticamente. Preencha os campos abaixo.');
      setAddress(null);
      return null;
    } finally {
      setLoading(false);
      abortControllerRef.current = null;
    }
  }, []);

  // Cleanup automático ao desmontar o componente
  useEffect(() => {
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, []);

  return {
    address,
    loading,
    error,
    fetchAddressByCep,
    resetAddress,
  };
};
