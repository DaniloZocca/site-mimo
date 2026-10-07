import { useState, useCallback, useRef } from 'react';
import { cleanNonDigits } from '../utils/validators';
import { API_BASE } from '../config/api';

// Cache em memória local volátil para evitar chamadas de rede repetidas na mesma sessão
const memoryCache = new Map();

/** Descarta cotações em memória (ex.: após o servidor devolver 409 shipping_price_changed). */
export const clearShippingCache = () => {
  memoryCache.clear();
};

/**
 * Normaliza uma opção vinda da API (/shipping/calculate ou shipping_options do 409) para o formato
 * guardado em selectedShipping. service_code é o identificador enviado ao checkout.
 */
export const normalizeShippingOption = (opt) => ({
  service_code: opt.service_code || 'pac',
  service_name: opt.service_name || 'PAC',
  carrier_name: opt.carrier_name || 'Correios',
  price_in_cents: Number(opt.price_in_cents ?? 0),
  price: Number(opt.price ?? ((opt.price_in_cents || 0) / 100)),
  price_formatted: opt.price_formatted || `R$ ${((opt.price_in_cents || 0) / 100).toFixed(2).replace('.', ',')}`,
  carrier_delivery_days: Number(opt.carrier_delivery_days ?? 3),
  manufacturing_lead_time_days: Number(opt.manufacturing_lead_time_days ?? 2),
  total_estimated_days: Number(opt.total_estimated_days ?? 5),
  icon_url: opt.icon_url || null,
});

/**
 * Hook de Cálculo e Gestão de Opções de Frete da MIMOO LAB
 */
export const useShipping = () => {
  const [shippingOptions, setShippingOptions] = useState([]);
  const [selectedShipping, setSelectedShipping] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const abortControllerRef = useRef(null);

  const selectOption = useCallback((option) => {
    setSelectedShipping(option);
  }, []);

  const resetShipping = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }
    setShippingOptions([]);
    setSelectedShipping(null);
    setError(null);
    setLoading(false);
  }, []);

  /**
   * Dispara a cotação de frete para o CEP de destino e itens fornecidos.
   *
   * @param {string} rawCep CEP digitado (com ou sem máscara)
   * @param {Array} items Lista de itens do carrinho
   * @returns {Promise<Array|null>}
   */
  const calculateShipping = useCallback(
    async (rawCep, items = []) => {
      const cleanCep = cleanNonDigits(rawCep || '');

      if (cleanCep.length !== 8) {
        setError('O CEP deve conter 8 dígitos.');
        setShippingOptions([]);
        setSelectedShipping(null);
        return null;
      }

      if (!items || items.length === 0) {
        setError('Adicione ao menos um item à sacola para calcular o frete.');
        return null;
      }

      // Função auxiliar para validar se um valor é um UUID v4 válido
      const isValidUuid = (val) =>
        typeof val === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(val);

      // Monta os itens simplificados para a API Zero-Trust: [{ product_id, size_id, quantity }]
      const simplifiedItems = items.map((item) => {
        const rawSizeId = item.size?.id || item.selectedSize?.id || item.sizeId || null;
        return {
          product_id: item.productId || item.product?.id || item.id,
          size_id: isValidUuid(rawSizeId) ? rawSizeId : null,
          quantity: Math.min(50, Math.max(1, Number(item.quantity) || 1)),
        };
      });

      // Chave de cache em memória
      const cacheKey = `${cleanCep}:${JSON.stringify(simplifiedItems)}`;
      if (memoryCache.has(cacheKey)) {
        const cachedOptions = memoryCache.get(cacheKey);
        setShippingOptions(cachedOptions);
        setError(null);
        if (cachedOptions.length > 0) {
          setSelectedShipping(cachedOptions[0]);
        }
        return cachedOptions;
      }

      // Cancela requisições anteriores em andamento
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      const controller = new AbortController();
      abortControllerRef.current = controller;

      setLoading(true);
      setError(null);

      try {
        const response = await fetch(`${API_BASE}/shipping/calculate`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify({
            destination_cep: cleanCep,
            items: simplifiedItems,
          }),
          signal: controller.signal,
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
          throw new Error(data.message || 'Não foi possível calcular o frete para este CEP.');
        }

        const rawOptions = data.options || [];
        const options = rawOptions.map((opt) => ({
          service_code: opt.service_code || 'pac',
          service_name: opt.service_name || 'PAC',
          carrier_name: opt.carrier_name || 'Correios',
          price_in_cents: Number(opt.price_in_cents ?? Math.round((opt.price || 0) * 100)),
          price: Number(opt.price ?? ((opt.price_in_cents || 0) / 100)),
          price_formatted: opt.price_formatted || `R$ ${((opt.price_in_cents || 0) / 100).toFixed(2).replace('.', ',')}`,
          carrier_delivery_days: Number(opt.carrier_delivery_days ?? 3),
          manufacturing_lead_time_days: Number(opt.manufacturing_lead_time_days ?? 2),
          total_estimated_days: Number(opt.total_estimated_days ?? (Number(opt.carrier_delivery_days || 3) + Number(opt.manufacturing_lead_time_days || 2))),
          icon_url: opt.icon_url || null,
        }));

        setShippingOptions(options);
        memoryCache.set(cacheKey, options);

        // Seleciona a primeira opção por padrão (geralmente PAC/Econômico)
        if (options.length > 0) {
          setSelectedShipping(options[0]);
        } else {
          setSelectedShipping(null);
        }

        setError(null);
        return options;
      } catch (err) {
        if (err.name === 'AbortError') {
          return null;
        }
        const errorMsg = err.message || 'Erro ao comunicar com a transportadora. Tente novamente.';
        setError(errorMsg);
        setShippingOptions([]);
        setSelectedShipping(null);
        return null;
      } finally {
        setLoading(false);
        abortControllerRef.current = null;
      }
    },
    []
  );

  return {
    shippingOptions,
    selectedShipping,
    loading,
    error,
    calculateShipping,
    selectOption,
    resetShipping,
  };
};
