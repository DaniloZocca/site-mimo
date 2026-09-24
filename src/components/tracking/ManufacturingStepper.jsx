import React from 'react';
import { 
  Clock, 
  Layers, 
  Printer, 
  Sparkles, 
  Truck, 
  HeartHandshake, 
  CheckCircle2, 
  AlertTriangle,
  Flame,
  Check
} from 'lucide-react';

const STEPS = [
  {
    id: 'pending_payment',
    label: 'Aguardando Pagamento',
    shortLabel: 'Pagamento',
    icon: Clock,
    color: 'amber',
    description: 'Aguardando liquidação do PIX ou autorização do cartão.',
  },
  {
    id: 'in_queue',
    label: 'Na Fila de Impressão',
    shortLabel: 'Na Fila',
    icon: Layers,
    color: 'purple',
    description: 'Filamento separado e ordem alocada para as impressoras.',
  },
  {
    id: 'printing',
    label: 'Imprimindo na Fazenda 3D',
    shortLabel: 'Extrusão 3D',
    icon: Printer,
    color: 'blue',
    description: 'Extrusão em camadas a 210°C em alta precisão.',
  },
  {
    id: 'finishing_and_packaging',
    label: 'Acabamento & Embalagem',
    shortLabel: 'Acabamento',
    icon: Sparkles,
    color: 'orange',
    description: 'Remoção de suportes, inspeção de qualidade e caixa com mimos.',
  },
  {
    id: 'shipped',
    label: 'Expedido / A Caminho',
    shortLabel: 'A Caminho',
    icon: Truck,
    color: 'emerald',
    description: 'Pacote despachado com código de rastreamento.',
  },
  {
    id: 'delivered',
    label: 'Entregue com Sucesso',
    shortLabel: 'Entregue',
    icon: HeartHandshake,
    color: 'pink',
    description: 'Criação 3D entregue no seu endereço com amor!',
  },
];

const STATUS_INDEX_MAP = {
  pending_payment: 0,
  in_queue: 1,
  printing: 2,
  finishing_and_packaging: 3,
  shipped: 4,
  delivered: 5,
};

/**
 * Stepper Visual do Fluxo Fabril 3D da MIMOO LAB
 *
 * @param {Object} props
 * @param {string} props.currentStatus
 */
export const ManufacturingStepper = ({ currentStatus = 'pending_payment' }) => {
  const isCancelled = currentStatus === 'cancelled';
  const currentIndex = STATUS_INDEX_MAP[currentStatus] ?? 0;
  const progressPercent = (currentIndex / (STEPS.length - 1)) * 100;

  // Banner especial caso a encomenda tenha sido cancelada
  if (isCancelled) {
    return (
      <div className="w-full p-5 rounded-3xl bg-red-50 border-2 border-red-200 text-red-900 shadow-chunky-sm flex items-start gap-4 animate-in fade-in">
        <div className="w-10 h-10 rounded-2xl bg-red-100 border border-red-300 text-red-600 flex items-center justify-center flex-shrink-0 font-bold">
          <AlertTriangle className="w-5 h-5" />
        </div>
        <div className="space-y-1 text-xs font-nunito">
          <strong className="font-fredoka text-sm text-red-800 font-bold block">
            Encomenda Cancelada
          </strong>
          <p className="text-red-700 leading-relaxed">
            Esta encomenda foi cancelada. Se você realizou o pagamento ou precisa de suporte, entre em contato com nosso atendimento pelo WhatsApp.
          </p>
        </div>
      </div>
    );
  }

  const activeStep = STEPS[currentIndex] || STEPS[0];
  const StepIcon = activeStep.icon;

  return (
    <div className="w-full bg-white border-2 border-mimoo-dark rounded-3xl p-5 sm:p-7 shadow-chunky space-y-6">
      
      {/* Banner de Destaque da Etapa Atual */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 rounded-2xl bg-gradient-to-r from-purple-50 via-pink-50 to-amber-50 border-2 border-mimoo-dark/15">
        <div className="flex items-center gap-3.5">
          <div className={`w-12 h-12 rounded-2xl border-2 border-mimoo-dark shadow-chunky-sm flex items-center justify-center text-white relative ${
            currentStatus === 'printing'
              ? 'bg-blue-600 animate-pulse'
              : currentStatus === 'pending_payment'
              ? 'bg-amber-500'
              : currentStatus === 'in_queue'
              ? 'bg-mimoo-purple'
              : currentStatus === 'finishing_and_packaging'
              ? 'bg-orange-500'
              : currentStatus === 'shipped'
              ? 'bg-emerald-600'
              : 'bg-pink-500'
          }`}>
            <StepIcon className="w-6 h-6" />
            {currentStatus === 'printing' && (
              <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-amber-400 border border-mimoo-dark rounded-full flex items-center justify-center animate-ping" />
            )}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-mimoo-dark text-white font-fredoka">
                Etapa {currentIndex + 1} de 6
              </span>
              {currentStatus === 'printing' && (
                <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-fredoka">
                  <Flame className="w-3 h-3 text-blue-600" /> Mesa a 60°C • Bico a 210°C
                </span>
              )}
            </div>
            <h3 className="font-fredoka font-bold text-lg text-mimoo-dark leading-tight mt-0.5">
              {activeStep.label}
            </h3>
            <p className="text-xs font-nunito text-slate-500">
              {activeStep.description}
            </p>
          </div>
        </div>
      </div>

      {/* Barra de Progresso e Nós do Stepper (Desktop & Tablet) */}
      <div className="hidden md:block relative pt-4 pb-2">
        {/* Linha Conectora de Fundo */}
        <div className="absolute top-[34px] left-8 right-8 h-2 bg-slate-100 rounded-full border border-slate-200 z-0" />
        
        {/* Linha Conectora Preenchida */}
        <div 
          className="absolute top-[34px] left-8 h-2 bg-gradient-to-r from-mimoo-purple to-emerald-500 rounded-full z-0 transition-all duration-700 ease-out"
          style={{ width: `calc(${progressPercent}% - 32px)` }}
        />

        {/* Grid de Nós dos Passos */}
        <div className="relative z-10 grid grid-cols-6 gap-2">
          {STEPS.map((step, idx) => {
            const isCompleted = idx < currentIndex;
            const isCurrent = idx === currentIndex;
            const isFuture = idx > currentIndex;
            const Icon = step.icon;

            return (
              <div key={step.id} className="flex flex-col items-center text-center group">
                {/* Círculo do Ícone */}
                <div className={`w-10 h-10 rounded-2xl border-2 flex items-center justify-center transition-all duration-300 shadow-chunky-sm ${
                  isCompleted
                    ? 'bg-emerald-500 border-mimoo-dark text-white scale-95'
                    : isCurrent
                    ? 'bg-mimoo-purple border-mimoo-dark text-white ring-4 ring-purple-100 scale-110'
                    : 'bg-white border-slate-300 text-slate-400'
                }`}>
                  {isCompleted ? (
                    <Check className="w-5 h-5 stroke-[3]" />
                  ) : (
                    <Icon className="w-4 h-4" />
                  )}
                </div>

                {/* Título da Etapa */}
                <span className={`text-xs font-fredoka mt-2.5 transition-colors line-clamp-1 ${
                  isCurrent
                    ? 'font-bold text-mimoo-purple'
                    : isCompleted
                    ? 'font-semibold text-slate-700'
                    : 'font-normal text-slate-400'
                }`}>
                  {step.shortLabel}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      {/* Lista Vertical Compacta do Stepper (Mobile) */}
      <div className="md:hidden space-y-3 pt-2">
        {STEPS.map((step, idx) => {
          const isCompleted = idx < currentIndex;
          const isCurrent = idx === currentIndex;
          const isFuture = idx > currentIndex;
          const Icon = step.icon;

          return (
            <div 
              key={step.id} 
              className={`flex items-center gap-3 p-2.5 rounded-2xl border transition-all ${
                isCurrent 
                  ? 'bg-purple-50/80 border-mimoo-purple shadow-chunky-sm' 
                  : isCompleted 
                  ? 'bg-emerald-50/40 border-emerald-200' 
                  : 'bg-slate-50 border-slate-200 opacity-60'
              }`}
            >
              <div className={`w-7 h-7 rounded-xl border flex items-center justify-center flex-shrink-0 text-xs font-bold ${
                isCompleted
                  ? 'bg-emerald-500 text-white border-emerald-600'
                  : isCurrent
                  ? 'bg-mimoo-purple text-white border-mimoo-dark'
                  : 'bg-white text-slate-400 border-slate-300'
              }`}>
                {isCompleted ? <Check className="w-4 h-4" /> : <Icon className="w-3.5 h-3.5" />}
              </div>

              <div className="flex-1 min-w-0">
                <span className={`block text-xs font-fredoka leading-tight ${
                  isCurrent ? 'font-bold text-mimoo-purple' : isCompleted ? 'font-semibold text-slate-700' : 'text-slate-400'
                }`}>
                  {step.label}
                </span>
                {isCurrent && (
                  <span className="text-[11px] font-nunito text-slate-500 block truncate">
                    {step.description}
                  </span>
                )}
              </div>
            </div>
          );
        })}
      </div>

    </div>
  );
};
