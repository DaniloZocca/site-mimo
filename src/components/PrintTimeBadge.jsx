import React from 'react';
import { Clock, Sparkles, Layers, ShieldCheck, Cpu } from 'lucide-react';

export const PrintTimeBadge = ({ hours, days, variant = 'default', className = '' }) => {
  if (variant === 'compact') {
    return (
      <span className={`inline-flex items-center gap-1 font-fredoka font-semibold text-xs px-2.5 py-1 rounded-full border-2 border-mimoo-dark bg-mimoo-yellow text-mimoo-dark shadow-chunky-sm ${className}`}>
        <Clock className="w-3.5 h-3.5 text-mimoo-purple" />
        <span>{hours ? `${hours}h de impressão` : days}</span>
      </span>
    );
  }

  if (variant === 'soft') {
    return (
      <span className={`inline-flex items-center gap-1.5 font-fredoka text-xs px-3 py-1 rounded-xl bg-mimoo-purple-soft text-mimoo-purple-dark border border-purple-200 font-semibold ${className}`}>
        <Clock className="w-3.5 h-3.5" />
        <span>Tempo de Impressão: <strong>{hours ? `~${hours}h` : days}</strong></span>
      </span>
    );
  }

  return (
    <div className={`inline-flex items-center gap-1.5 bg-gradient-to-r from-mimoo-yellow to-amber-300 text-mimoo-dark px-3 py-1 rounded-full border-2 border-mimoo-dark shadow-chunky-sm font-fredoka text-xs font-bold ${className}`}>
      <Clock className="w-3.5 h-3.5 text-mimoo-purple" />
      <span>{hours ? `⏱️ Produção em ~${hours} horas` : `⏱️ Produção: ${days}`}</span>
    </div>
  );
};

export const PrintTimeCallout = ({ hours = 14, days = '1 a 2 dias úteis', sizeLabel }) => {
  return (
    <div className="relative overflow-hidden rounded-3xl border-2 border-mimoo-dark bg-gradient-to-br from-mimoo-purple-tint via-white to-mimoo-pink-light p-4 sm:p-5 shadow-chunky">
      {/* Decorative background circle */}
      <div className="absolute -right-8 -bottom-8 w-28 h-28 bg-mimoo-pink/15 rounded-full blur-xl pointer-events-none" />
      
      <div className="flex items-start gap-3.5">
        {/* Mascot Avatar Icon */}
        <div className="relative flex-shrink-0 w-12 h-12 rounded-2xl border-2 border-mimoo-dark bg-white shadow-chunky-sm p-1 flex items-center justify-center animate-bounce-soft">
          <img 
            src="/assets/logo-mascot.png" 
            alt="Mascote Mimoo" 
            className="w-full h-full object-contain"
            onError={(e) => {
              e.target.onerror = null;
              e.target.src = '/assets/logo-full.png';
            }}
          />
          <div className="absolute -bottom-1 -right-1 w-4 h-4 bg-mimoo-yellow rounded-full border border-mimoo-dark flex items-center justify-center">
            <Sparkles className="w-2.5 h-2.5 text-mimoo-dark" />
          </div>
        </div>

        {/* Content Details */}
        <div className="flex-1 min-w-0">
          <div className="flex flex-wrap items-center gap-2 mb-1">
            <span className="font-fredoka font-bold text-sm sm:text-base text-mimoo-dark">
              Peça Feita Sob Encomenda com Carinho! ✨
            </span>
            <span className="badge-chunky bg-mimoo-yellow text-mimoo-dark">
              Fatia & Imprime
            </span>
          </div>

          <p className="text-xs sm:text-sm text-slate-600 font-nunito leading-relaxed mb-3">
            Esta peça não fica parada em estoque. Assim que seu pedido é aprovado, nossa impressora 3D é calibrada para fabricar sua peça no filamento que você escolheu!
          </p>

          {/* Metric badges row */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs font-fredoka">
            <div className="flex items-center gap-2 bg-white/90 p-2 rounded-xl border border-purple-200">
              <Clock className="w-4 h-4 text-mimoo-purple flex-shrink-0" />
              <div>
                <span className="text-slate-500 block text-[10px]">Tempo de Máquina:</span>
                <strong className="text-mimoo-dark text-xs sm:text-sm">
                  ~{hours}h de extrusão ({days})
                </strong>
              </div>
            </div>

            <div className="flex items-center gap-2 bg-white/90 p-2 rounded-xl border border-pink-200">
              <ShieldCheck className="w-4 h-4 text-mimoo-pink flex-shrink-0" />
              <div>
                <span className="text-slate-500 block text-[10px]">Inspeção & Cura:</span>
                <strong className="text-mimoo-dark text-xs sm:text-sm">
                  Acabamento manual + mimos
                </strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
