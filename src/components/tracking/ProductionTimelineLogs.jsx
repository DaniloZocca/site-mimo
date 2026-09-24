import React from 'react';
import { 
  ClipboardList, 
  Clock, 
  Layers, 
  Printer, 
  Sparkles, 
  Truck, 
  HeartHandshake, 
  AlertCircle,
  Wrench,
  CheckCircle2
} from 'lucide-react';

const STATUS_CONFIG = {
  created: {
    label: 'Pedido Criado',
    color: 'bg-slate-100 text-slate-700 border-slate-300',
    dotColor: 'bg-slate-400',
    icon: Clock,
  },
  pending_payment: {
    label: 'Aguardando Pagamento',
    color: 'bg-amber-100 text-amber-800 border-amber-300',
    dotColor: 'bg-amber-500',
    icon: Clock,
  },
  in_queue: {
    label: 'Na Fila de Impressão',
    color: 'bg-purple-100 text-purple-800 border-purple-300',
    dotColor: 'bg-mimoo-purple',
    icon: Layers,
  },
  printing: {
    label: 'Imprimindo na Fazenda 3D',
    color: 'bg-blue-100 text-blue-800 border-blue-300',
    dotColor: 'bg-blue-600',
    icon: Printer,
  },
  finishing_and_packaging: {
    label: 'Acabamento & Embalagem',
    color: 'bg-orange-100 text-orange-800 border-orange-300',
    dotColor: 'bg-orange-500',
    icon: Sparkles,
  },
  shipped: {
    label: 'Expedido / A Caminho',
    color: 'bg-emerald-100 text-emerald-800 border-emerald-300',
    dotColor: 'bg-emerald-600',
    icon: Truck,
  },
  delivered: {
    label: 'Entregue',
    color: 'bg-pink-100 text-pink-800 border-pink-300',
    dotColor: 'bg-pink-500',
    icon: HeartHandshake,
  },
  cancelled: {
    label: 'Cancelado',
    color: 'bg-red-100 text-red-800 border-red-300',
    dotColor: 'bg-red-500',
    icon: AlertCircle,
  },
};

/**
 * Linha do Tempo Cronológica de Logs da Oficina 3D (Diário Fabril)
 *
 * @param {Object} props
 * @param {Array<Object>} props.logs - Lista ordenada de eventos de produção
 */
export const ProductionTimelineLogs = ({ logs = [] }) => {
  const hasLogs = Array.isArray(logs) && logs.length > 0;

  return (
    <div className="w-full bg-white border-2 border-mimoo-dark rounded-3xl p-5 sm:p-7 shadow-chunky space-y-6 animate-in fade-in duration-300">
      
      {/* Header da Timeline */}
      <div className="flex items-center justify-between pb-3 border-b-2 border-slate-100">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-xl bg-purple-100 text-mimoo-purple border-2 border-mimoo-dark/20 flex items-center justify-center font-bold">
            <ClipboardList className="w-5 h-5" />
          </div>
          <div>
            <h3 className="font-fredoka font-bold text-lg text-mimoo-dark leading-tight">
              Diário da Oficina 3D
            </h3>
            <p className="text-xs font-nunito text-slate-500">
              Histórico cronológico de manufatura registrado pelos nossos técnicos.
            </p>
          </div>
        </div>

        {hasLogs && (
          <span className="text-[11px] font-fredoka font-bold px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
            {logs.length} {logs.length === 1 ? 'evento' : 'eventos'}
          </span>
        )}
      </div>

      {/* Lista da Timeline */}
      {hasLogs ? (
        <div className="relative pl-6 space-y-6 before:absolute before:left-[11px] before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-200">
          {logs.map((log, index) => {
            const rawStatus = log.new_status || log.status || 'in_queue';
            const previousStatus = log.previous_status || log.previousStatus || null;
            const config = STATUS_CONFIG[rawStatus] || STATUS_CONFIG.in_queue;
            const prevConfig = previousStatus ? STATUS_CONFIG[previousStatus] : null;
            const Icon = config.icon;

            // Formatação do carimbo de data/hora
            const dateStr = log.timestamp || log.created_at || '';
            const description = log.description || log.notes || 'Atualização de status no sistema.';

            return (
              <div key={log.id || index} className="relative group">
                {/* Nó Circular na Linha */}
                <div className={`absolute -left-[30px] top-1.5 w-6 h-6 rounded-full border-2 border-white shadow-sm flex items-center justify-center text-white ${config.dotColor} group-hover:scale-110 transition-transform`}>
                  <Icon className="w-3 h-3" />
                </div>

                {/* Card do Evento */}
                <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 shadow-chunky-sm space-y-2 hover:bg-white hover:border-mimoo-purple/30 transition-all">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    {/* Badges de Transição */}
                    <div className="flex items-center gap-1.5 flex-wrap">
                      {prevConfig && (
                        <>
                          <span className={`text-[10px] font-fredoka font-bold px-2 py-0.5 rounded-full border ${prevConfig.color}`}>
                            {prevConfig.label}
                          </span>
                          <span className="text-slate-400 text-xs font-bold font-fredoka">➔</span>
                        </>
                      )}
                      <span className={`text-[10px] font-fredoka font-bold px-2.5 py-0.5 rounded-full border ${config.color}`}>
                        {config.label}
                      </span>
                    </div>

                    {/* Data / Hora formatada */}
                    {dateStr && (
                      <span className="text-[11px] font-nunito font-semibold text-slate-400 flex items-center gap-1">
                        <Clock className="w-3 h-3" />
                        <span>{dateStr}</span>
                      </span>
                    )}
                  </div>

                  {/* Notas Técnicas do Operador */}
                  <p className="text-xs font-nunito text-slate-700 leading-relaxed font-normal bg-white p-2.5 rounded-xl border border-slate-100">
                    {description}
                  </p>
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        /* Fallback Informativo */
        <div className="p-6 rounded-2xl bg-slate-50 border border-slate-200 text-center space-y-2">
          <div className="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center mx-auto">
            <Wrench className="w-5 h-5" />
          </div>
          <strong className="font-fredoka text-sm text-slate-700 block">
            Aguardando Primeiro Apontamento Técnico
          </strong>
          <p className="text-xs font-nunito text-slate-500 max-w-sm mx-auto">
            Assim que nossa equipe de impressão 3D iniciar a preparação do filamento ou a extrusão da sua peça, os registros técnicos detalhados aparecerão aqui.
          </p>
        </div>
      )}

    </div>
  );
};
