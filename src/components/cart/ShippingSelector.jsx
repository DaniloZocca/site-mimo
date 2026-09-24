import React, { useState, useEffect } from 'react';
import { 
  Truck, 
  Search, 
  Loader2, 
  Check, 
  Sparkles, 
  Clock, 
  Printer, 
  HelpCircle,
  AlertCircle
} from 'lucide-react';
import { formatCEP, cleanNonDigits } from '../../utils/validators';
import { useShipping } from '../../hooks/useShipping';
import { useCart } from '../../context/CartContext';

/**
 * Componente Visual de Cálculo e Seleção de Frete da MIMOO LAB
 *
 * @param {Object} props
 * @param {Array} props.items - Itens do carrinho para cálculo volumétrico
 * @param {Function} [props.onShippingSelected] - Callback disparado ao selecionar uma opção
 * @param {Object} [props.selectedOption] - Opção atualmente selecionada
 * @param {string} [props.initialCep] - CEP inicial
 * @param {boolean} [props.compact] - Modo compacto para gaveta de carrinho
 */
export const ShippingSelector = ({
  items = [],
  onShippingSelected,
  selectedOption = null,
  initialCep = '',
  compact = false,
}) => {
  const [cep, setCep] = useState(initialCep ? formatCEP(initialCep) : '');
  const { 
    selectedShipping: contextShipping, 
    setSelectedShipping: setContextShipping 
  } = useCart();

  const { 
    shippingOptions, 
    selectedShipping, 
    loading, 
    error, 
    calculateShipping, 
    selectOption 
  } = useShipping();

  useEffect(() => {
    if (initialCep) {
      const formatted = formatCEP(initialCep);
      setCep(formatted);
      if (cleanNonDigits(formatted).length === 8 && items.length > 0) {
        calculateShipping(formatted, items);
      }
    }
  }, [initialCep]);

  // Sincroniza a opção selecionada com o contexto global e com callback pai
  useEffect(() => {
    if (selectedShipping) {
      const normalized = {
        service_name: selectedShipping.service_name || 'PAC',
        carrier_name: selectedShipping.carrier_name || 'Correios',
        price_in_cents: Number(selectedShipping.price_in_cents ?? Math.round((selectedShipping.price || 0) * 100)),
        carrier_delivery_days: Number(selectedShipping.carrier_delivery_days ?? 3),
        total_estimated_days: Number(selectedShipping.total_estimated_days ?? 5),
        manufacturing_lead_time_days: Number(selectedShipping.manufacturing_lead_time_days ?? 2),
        service_code: selectedShipping.service_code || 'pac',
        price: Number(selectedShipping.price ?? ((selectedShipping.price_in_cents || 0) / 100)),
        price_formatted: selectedShipping.price_formatted || `R$ ${((selectedShipping.price_in_cents || 0) / 100).toFixed(2).replace('.', ',')}`,
      };

      if (setContextShipping) {
        setContextShipping(normalized);
      }
      if (typeof onShippingSelected === 'function') {
        onShippingSelected(normalized);
      }
    }
  }, [selectedShipping, setContextShipping, onShippingSelected]);

  const handleOptionClick = (option) => {
    const normalized = {
      service_name: option.service_name || 'PAC',
      carrier_name: option.carrier_name || 'Correios',
      price_in_cents: Number(option.price_in_cents ?? Math.round((option.price || 0) * 100)),
      carrier_delivery_days: Number(option.carrier_delivery_days ?? 3),
      total_estimated_days: Number(option.total_estimated_days ?? 5),
      manufacturing_lead_time_days: Number(option.manufacturing_lead_time_days ?? 2),
      service_code: option.service_code || 'pac',
      price: Number(option.price ?? ((option.price_in_cents || 0) / 100)),
      price_formatted: option.price_formatted || `R$ ${((option.price_in_cents || 0) / 100).toFixed(2).replace('.', ',')}`,
    };

    selectOption(normalized);
    if (setContextShipping) {
      setContextShipping(normalized);
    }
    if (typeof onShippingSelected === 'function') {
      onShippingSelected(normalized);
    }
  };

  const handleCepChange = (e) => {
    const formatted = formatCEP(e.target.value);
    setCep(formatted);

    const clean = cleanNonDigits(formatted);
    if (clean.length === 8 && items.length > 0) {
      calculateShipping(clean, items);
    }
  };

  const handleCalculateClick = (e) => {
    e.preventDefault();
    const clean = cleanNonDigits(cep);
    if (clean.length === 8) {
      calculateShipping(clean, items);
    }
  };

  const currentSelection = selectedOption || contextShipping || selectedShipping;

  return (
    <div className={`w-full space-y-3 font-nunito ${compact ? 'text-xs' : 'text-sm'}`}>
      
      {/* Header do Frete */}
      <div className="flex items-center justify-between">
        <label className="font-fredoka font-bold text-slate-700 flex items-center gap-1.5 text-xs">
          <Truck className="w-4 h-4 text-mimoo-purple" />
          <span>Calcular Frete e Prazo de Entrega:</span>
        </label>
        
        <a
          href="https://buscacepinter.correios.com.br/app/endereco/index.php"
          target="_blank"
          rel="noopener noreferrer"
          className="text-[11px] text-mimoo-purple hover:underline font-semibold flex items-center gap-1"
        >
          <HelpCircle className="w-3 h-3" />
          <span>Não sei meu CEP</span>
        </a>
      </div>

      {/* Input de CEP e Botão Calcular */}
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <input
            type="text"
            placeholder="00000-000"
            maxLength={9}
            value={cep}
            onChange={handleCepChange}
            disabled={loading}
            className="w-full px-3.5 py-2.5 rounded-xl border-2 border-slate-300 bg-white text-xs font-mono text-slate-800 outline-none focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20 transition-all disabled:opacity-60"
          />
        </div>

        <button
          type="button"
          onClick={handleCalculateClick}
          disabled={loading || cleanNonDigits(cep).length !== 8}
          className="btn-chunky-purple py-2.5 px-4 text-xs font-fredoka font-bold flex items-center justify-center gap-1.5 shadow-chunky-sm disabled:opacity-50 whitespace-nowrap"
        >
          {loading ? (
            <>
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
              <span>Calculando...</span>
            </>
          ) : (
            <>
              <Search className="w-3.5 h-3.5" />
              <span>Calcular</span>
            </>
          )}
        </button>
      </div>

      {/* Alerta de Erro */}
      {error && (
        <div className="p-3 rounded-xl bg-pink-50 border border-pink-200 text-pink-900 text-xs flex items-center gap-2 animate-in fade-in">
          <AlertCircle className="w-4 h-4 text-pink-600 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Lista de Opções de Frete (PAC e SEDEX com SLA Desmembrado) */}
      {shippingOptions.length > 0 && (
        <div className="space-y-2 pt-1 animate-in fade-in duration-200">
          {shippingOptions.map((option, index) => {
            const isSelected = currentSelection?.service_code === option.service_code;

            return (
              <div
                key={option.service_code || index}
                onClick={() => handleOptionClick(option)}
                className={`p-3 rounded-2xl border-2 cursor-pointer transition-all flex flex-col justify-between gap-1.5 shadow-chunky-sm ${
                  isSelected
                    ? 'border-mimoo-purple bg-purple-50/80 ring-2 ring-mimoo-purple/30'
                    : 'border-slate-200 bg-white hover:border-slate-300'
                }`}
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <div className={`w-4 h-4 rounded-full border-2 flex items-center justify-center ${
                      isSelected ? 'border-mimoo-purple bg-mimoo-purple text-white' : 'border-slate-300 bg-white'
                    }`}>
                      {isSelected && <div className="w-1.5 h-1.5 rounded-full bg-white" />}
                    </div>

                    <span className="font-fredoka font-bold text-xs text-mimoo-dark">
                      {option.carrier_name} {option.service_name}
                    </span>
                  </div>

                  <span className="font-fredoka font-bold text-xs text-mimoo-purple">
                    {option.price_formatted}
                  </span>
                </div>

                {/* Badge e Subtexto Explicativo do Prazo Combinado */}
                <div className="pl-6 space-y-0.5 text-[11px] text-slate-500">
                  <div className="flex items-center gap-1.5 font-bold text-slate-700">
                    <Clock className="w-3.5 h-3.5 text-mimoo-purple" />
                    <span>Prazo Total: {option.total_estimated_days} dias úteis</span>
                  </div>

                  <p className="text-[10px] text-slate-400">
                    🖨️ {option.manufacturing_lead_time_days}d de impressão 3D + 🚚 {option.carrier_delivery_days}d de transporte Correios
                  </p>
                </div>
              </div>
            );
          })}

          {/* Aviso Lúdico */}
          <p className="text-[10px] text-slate-400 leading-snug pt-1 text-center">
            ✨ Produção sob demanda: iniciamos a impressão logo após a confirmação do pagamento!
          </p>
        </div>
      )}

    </div>
  );
};
