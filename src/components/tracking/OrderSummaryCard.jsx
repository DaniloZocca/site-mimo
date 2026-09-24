import React, { useState } from 'react';
import { 
  Package, 
  Truck, 
  Copy, 
  Check, 
  ExternalLink, 
  Clock, 
  MapPin, 
  Calendar,
  Layers,
  Sparkles
} from 'lucide-react';

/**
 * Card de Resumo da Encomenda e Detalhes dos Itens 3D
 *
 * @param {Object} props
 * @param {Object} props.order - Dados normalizados do pedido (LGPD compliant)
 */
export const OrderSummaryCard = ({ order }) => {
  const [copiedCode, setCopiedCode] = useState(false);

  if (!order) return null;

  const handleCopyTrackingCode = () => {
    if (order.carrierTrackingCode) {
      navigator.clipboard.writeText(order.carrierTrackingCode);
      setCopiedCode(true);
      setTimeout(() => setCopiedCode(false), 3000);
    }
  };

  const hasShipping = Boolean(order.carrierTrackingCode);

  return (
    <div className="w-full bg-white border-2 border-mimoo-dark rounded-3xl p-5 sm:p-7 shadow-chunky space-y-6 animate-in fade-in duration-300">
      
      {/* Header do Resumo */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b-2 border-slate-100">
        <div>
          <span className="text-[11px] font-fredoka font-bold uppercase tracking-wider text-slate-400 block">
            Código da Encomenda
          </span>
          <h3 className="font-fredoka font-bold text-xl sm:text-2xl text-mimoo-dark tracking-wide">
            {order.orderNumber}
          </h3>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* Badge de Destino LGPD */}
          <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-xs font-nunito font-semibold">
            <MapPin className="w-3.5 h-3.5 text-mimoo-purple" />
            <span>Destino: {order.destination}</span>
          </div>

          {/* Badge de Data de Criação */}
          {order.createdAt && (
            <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-purple-50 border border-purple-200 text-mimoo-purple text-xs font-fredoka font-bold">
              <Calendar className="w-3.5 h-3.5" />
              <span>Pedido em: {order.createdAt}</span>
            </div>
          )}
        </div>
      </div>

      {/* Seção de Rastreio dos Correios (Se despachado) */}
      {hasShipping && (
        <div className="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-emerald-50 to-teal-50 border-2 border-emerald-300 space-y-3 shadow-chunky-sm">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2 font-fredoka font-bold text-emerald-900 text-sm">
              <Truck className="w-5 h-5 text-emerald-600" />
              <span>Código de Rastreamento dos Correios</span>
            </div>
            <span className="px-2.5 py-0.5 rounded-full bg-emerald-200 text-emerald-900 text-[10px] font-bold font-fredoka uppercase">
              SEDEX / PAC
            </span>
          </div>

          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 pt-1">
            <div className="flex-1 bg-white border-2 border-emerald-400 rounded-xl px-3.5 py-2 font-mono text-sm font-bold text-emerald-950 tracking-wider flex items-center justify-between">
              <span>{order.carrierTrackingCode}</span>
            </div>

            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={handleCopyTrackingCode}
                className="btn-chunky-pink py-2 px-3.5 text-xs font-bold font-fredoka flex items-center justify-center gap-1.5 whitespace-nowrap shadow-chunky-sm"
              >
                {copiedCode ? <Check className="w-4 h-4 text-emerald-700" /> : <Copy className="w-4 h-4" />}
                <span>{copiedCode ? 'Copiado!' : 'Copiar Código'}</span>
              </button>

              <a
                href={`https://rastreamento.correios.com.br/app/index.php?codigo=${order.carrierTrackingCode}`}
                target="_blank"
                rel="noopener noreferrer"
                className="btn-chunky-purple py-2 px-3.5 text-xs font-bold font-fredoka flex items-center justify-center gap-1.5 whitespace-nowrap shadow-chunky-sm"
              >
                <span>Site dos Correios</span>
                <ExternalLink className="w-3.5 h-3.5" />
              </a>
            </div>
          </div>
        </div>
      )}

      {/* Lista de Peças 3D Encomendadas */}
      <div className="space-y-3">
        <h4 className="font-fredoka font-bold text-xs uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1.5 flex items-center gap-2">
          <Package className="w-4 h-4 text-mimoo-purple" />
          <span>Peças em Manufatura Sob Demanda ({order.items.length})</span>
        </h4>

        <div className="divide-y divide-slate-100">
          {order.items.map((item, index) => (
            <div key={index} className="py-3 flex items-center justify-between gap-4 font-nunito">
              <div className="flex items-center gap-3">
                {/* Thumbnail Icon */}
                <div className="w-11 h-11 rounded-xl bg-purple-50 border-2 border-mimoo-dark/15 flex items-center justify-center flex-shrink-0 text-mimoo-purple font-bold">
                  <Layers className="w-5 h-5" />
                </div>

                <div className="space-y-0.5">
                  <h5 className="font-fredoka font-bold text-sm text-mimoo-dark leading-snug">
                    {item.title}
                  </h5>

                  <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    {/* Amostra Visual da Cor */}
                    <span className="inline-flex items-center gap-1.5 font-semibold text-slate-700 bg-slate-100 px-2 py-0.5 rounded-md">
                      <span className="w-2.5 h-2.5 rounded-full bg-mimoo-purple border border-slate-300 inline-block" />
                      {item.color}
                    </span>

                    {/* Escala */}
                    <span className="font-medium bg-slate-100 px-2 py-0.5 rounded-md text-slate-600">
                      📏 {item.size}
                    </span>
                  </div>
                </div>
              </div>

              {/* Quantidade */}
              <div className="text-right font-fredoka flex-shrink-0">
                <span className="text-xs text-slate-400 block">Qtd</span>
                <span className="font-bold text-sm text-mimoo-dark">x{item.quantity}</span>
              </div>
            </div>
          ))}
        </div>
      </div>

    </div>
  );
};
