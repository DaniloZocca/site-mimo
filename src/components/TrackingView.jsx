import React, { useEffect, useState } from 'react';
import confetti from 'canvas-confetti';
import { 
  Search, 
  RotateCcw, 
  Sparkles, 
  Package, 
  Truck, 
  Clock, 
  ShieldCheck, 
  HelpCircle, 
  MessageCircle,
  CheckCircle2,
  Layers,
  ArrowRight
} from 'lucide-react';
import { useShop } from '../context/ShopContext';
import { useOrderTracking } from '../hooks/useOrderTracking';
import { OrderSearchForm } from './tracking/OrderSearchForm';
import { ManufacturingStepper } from './tracking/ManufacturingStepper';
import { OrderSummaryCard } from './tracking/OrderSummaryCard';
import { ProductionTimelineLogs } from './tracking/ProductionTimelineLogs';
import { ShareTrackingCard } from './tracking/ShareTrackingCard';

export const TrackingView = () => {
  const { setActiveTab } = useShop();
  const { 
    order, 
    logs, 
    loading, 
    error, 
    rateLimited, 
    trackByToken, 
    trackByCredentials, 
    resetState 
  } = useOrderTracking();

  const [showSuccessBanner, setShowSuccessBanner] = useState(false);

  // Leitura de parâmetros especiais na URL (ex: ?success=true pós-checkout)
  useEffect(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const isSuccess = urlParams.get('success') === 'true';

    if (isSuccess) {
      setShowSuccessBanner(true);
      confetti({
        particleCount: 100,
        spread: 70,
        origin: { y: 0.6 },
        colors: ['#7C3AED', '#EC4899', '#10B981', '#F59E0B'],
      });
    }
  }, []);

  const handleSearch = ({ trackingToken, orderNumber, identifier }) => {
    if (trackingToken) {
      trackByToken(trackingToken);
    } else if (orderNumber && identifier) {
      trackByCredentials(orderNumber, identifier);
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 space-y-8 animate-in fade-in duration-300">
      
      {/* Banner Festivo de Compra Concluída (?success=true) */}
      {showSuccessBanner && (
        <div className="p-4 sm:p-5 rounded-3xl bg-gradient-to-r from-purple-600 via-pink-500 to-amber-400 text-white shadow-chunky flex items-center justify-between gap-4 animate-in slide-in-from-top duration-500">
          <div className="flex items-center gap-3">
            <span className="text-2xl">🎉</span>
            <div>
              <h3 className="font-fredoka font-bold text-base sm:text-lg leading-tight">
                Encomenda Confirmada com Sucesso!
              </h3>
              <p className="text-xs font-nunito text-white/90">
                Sua peça já foi registrada. Acompanhe abaixo cada etapa de manufatura em tempo real na fazenda 3D.
              </p>
            </div>
          </div>

          <button
            type="button"
            onClick={() => setShowSuccessBanner(false)}
            className="p-1.5 rounded-xl bg-white/20 hover:bg-white/30 transition-all text-white text-xs font-fredoka font-bold px-3"
          >
            Dispensar
          </button>
        </div>
      )}

      {/* ESTADO 1: CARREGAMENTO ATIVO (SKELETON ANIMADO) */}
      {loading && (
        <div className="space-y-6 animate-pulse">
          {/* Skeleton do Header e Stepper */}
          <div className="w-full bg-white border-2 border-slate-200 rounded-3xl p-6 sm:p-8 space-y-6">
            <div className="h-10 bg-slate-200 rounded-2xl w-2/3 max-w-sm" />
            <div className="h-14 bg-slate-100 rounded-2xl w-full" />
            <div className="grid grid-cols-6 gap-3">
              {[1, 2, 3, 4, 5, 6].map((i) => (
                <div key={i} className="flex flex-col items-center gap-2">
                  <div className="w-10 h-10 rounded-2xl bg-slate-200" />
                  <div className="h-3 bg-slate-200 rounded-md w-12" />
                </div>
              ))}
            </div>
          </div>

          {/* Skeleton do Grid Principal */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div className="lg:col-span-7 space-y-6">
              <div className="h-64 bg-white border-2 border-slate-200 rounded-3xl p-6" />
              <div className="h-36 bg-white border-2 border-slate-200 rounded-3xl p-6" />
            </div>
            <div className="lg:col-span-5">
              <div className="h-96 bg-white border-2 border-slate-200 rounded-3xl p-6" />
            </div>
          </div>
        </div>
      )}

      {/* ESTADO 2: SUCESSO - PEDIDO LOCALIZADO */}
      {!loading && order && (
        <div className="space-y-6 animate-in zoom-in-95 duration-300">
          
          {/* Barra Superior do Pedido Encontrado */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-5 rounded-3xl bg-white border-2 border-mimoo-dark shadow-chunky">
            <div className="flex items-center gap-3">
              <div className="w-12 h-12 rounded-2xl bg-purple-100 text-mimoo-purple border-2 border-mimoo-dark flex items-center justify-center font-bold shadow-chunky-sm">
                <Package className="w-6 h-6" />
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-[11px] font-fredoka font-bold text-slate-400 uppercase">
                    Encomenda Sob Demanda
                  </span>
                  <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800 font-fredoka">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping" />
                    Ao Vivo
                  </span>
                </div>
                <h1 className="font-fredoka font-bold text-2xl text-mimoo-dark leading-tight">
                  {order.orderNumber}
                </h1>
              </div>
            </div>

            {/* Ações Rápidas */}
            <div className="flex items-center gap-2.5 w-full sm:w-auto">
              <button
                type="button"
                onClick={resetState}
                className="btn-chunky-pink py-2.5 px-4 text-xs font-fredoka font-bold flex items-center justify-center gap-1.5 shadow-chunky-sm w-full sm:w-auto"
              >
                <RotateCcw className="w-4 h-4" />
                <span>Nova Consulta</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('catalog')}
                className="btn-chunky-purple py-2.5 px-4 text-xs font-fredoka font-bold flex items-center justify-center gap-1.5 shadow-chunky-sm w-full sm:w-auto"
              >
                <span>Ver Catálogo 3D</span>
                <ArrowRight className="w-4 h-4" />
              </button>
            </div>
          </div>

          {/* 1. Stepper Visual da Produção Fabril 3D */}
          <ManufacturingStepper currentStatus={order.status} />

          {/* 2. Grid de 2 Colunas: Resumo + Compartilhamento e Diário da Oficina */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            {/* Coluna 1 (Esquerda - 7 Colunas): Detalhes da Encomenda & Compartilhamento */}
            <div className="lg:col-span-7 space-y-6">
              <OrderSummaryCard order={order} />
              <ShareTrackingCard trackingToken={order.trackingToken} orderNumber={order.orderNumber} />
            </div>

            {/* Coluna 2 (Direita - 5 Colunas): Linha do Tempo de Logs da Oficina */}
            <div className="lg:col-span-5">
              <ProductionTimelineLogs logs={logs} />
            </div>

          </div>

        </div>
      )}

      {/* ESTADO 3: INICIAL OU ERRO (SEM BUSCA OU NÃO ENCONTRADO) */}
      {!loading && !order && (
        <div className="space-y-12">
          
          {/* Seção Principal: Hero da Central de Rastreamento & Formulário */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
            
            {/* Texto de Boas-Vindas e Mascote */}
            <div className="lg:col-span-6 space-y-4 text-center lg:text-left">
              <div className="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-mimoo-pink-light border border-pink-200 text-pink-700 text-xs font-fredoka font-bold">
                <Sparkles className="w-4 h-4 text-mimoo-purple" />
                <span>Transparência de Ponta a Ponta</span>
              </div>

              <h1 className="font-fredoka font-bold text-3xl sm:text-4xl text-mimoo-dark leading-tight">
                Onde está a minha <span className="text-mimoo-purple">criação 3D?</span> 🐮✨
              </h1>

              <p className="font-nunito text-slate-600 text-sm sm:text-base leading-relaxed max-w-lg mx-auto lg:mx-0">
                Cada item da <strong>MIMOO LAB</strong> é produzido artesanalmente em nossas impressoras 3D. 
                Digite o número da sua encomenda ou cole o seu token secreto para ver a temperatura da mesa, o tempo restante e o código de envio!
              </p>

              {/* Badges de Confiança */}
              <div className="flex flex-wrap items-center justify-center lg:justify-start gap-3 pt-2 font-fredoka text-xs">
                <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 shadow-chunky-sm">
                  <ShieldCheck className="w-4 h-4 text-emerald-600" />
                  <span>Privacidade LGPD</span>
                </div>
                <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 shadow-chunky-sm">
                  <Clock className="w-4 h-4 text-mimoo-purple" />
                  <span>Status em Tempo Real</span>
                </div>
              </div>
            </div>

            {/* Formulário de Busca Integrado */}
            <div className="lg:col-span-6">
              <OrderSearchForm
                onSearch={handleSearch}
                loading={loading}
                error={error}
                rateLimited={rateLimited}
              />
            </div>

          </div>

          {/* Suporte Direto caso o cliente tenha dúvidas sobre o código */}
          {error && (
            <div className="p-5 rounded-3xl bg-pink-50 border-2 border-pink-200 text-pink-950 flex flex-col sm:flex-row items-center justify-between gap-4 max-w-3xl mx-auto shadow-chunky-sm">
              <div className="flex items-center gap-3 text-center sm:text-left">
                <div className="w-10 h-10 rounded-2xl bg-white text-pink-600 border border-pink-300 flex items-center justify-center flex-shrink-0 font-bold">
                  <HelpCircle className="w-5 h-5" />
                </div>
                <div>
                  <strong className="font-fredoka text-sm block">
                    Não encontrou seu pedido?
                  </strong>
                  <p className="text-xs font-nunito text-slate-600">
                    Se você acabou de pagar ou perdeu o código da encomenda, nossa equipe pode te ajudar imediatamente.
                  </p>
                </div>
              </div>

              <a
                href="https://wa.me/5511999999999?text=Ol%C3%A1!%20Gostaria%20de%20ajuda%20para%20rastrear%20minha%20encomenda%203D%20na%20MIMOO%20LAB."
                target="_blank"
                rel="noopener noreferrer"
                className="btn-chunky-purple py-2.5 px-5 text-xs font-fredoka font-bold flex items-center gap-2 whitespace-nowrap shadow-chunky-sm"
              >
                <MessageCircle className="w-4 h-4" />
                <span>Atendimento no WhatsApp</span>
              </a>
            </div>
          )}

          {/* Seção Educativa: Como Funciona a Produção 3D da MIMOO */}
          <div className="bg-white border-2 border-mimoo-dark rounded-3xl p-6 sm:p-10 shadow-chunky space-y-6">
            <div className="text-center space-y-1">
              <h3 className="font-fredoka font-bold text-xl sm:text-2xl text-mimoo-dark">
                O Ciclo de Vida da sua Encomenda Sob Demanda
              </h3>
              <p className="text-xs font-nunito text-slate-500">
                Do filamento ecológico direto para o aconchego da sua casa.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
              
              <div className="p-5 rounded-2xl bg-purple-50/60 border-2 border-mimoo-dark/15 space-y-2">
                <div className="w-10 h-10 rounded-xl bg-mimoo-purple text-white flex items-center justify-center font-bold text-sm shadow-chunky-sm">
                  1
                </div>
                <h4 className="font-fredoka font-bold text-sm text-mimoo-dark">
                  Separação & Fatiamento
                </h4>
                <p className="text-xs font-nunito text-slate-600 leading-relaxed">
                  Assim que o pagamento é aprovado, o filamento na sua cor escolhida é preparado e a impressora 3D é calibrada.
                </p>
              </div>

              <div className="p-5 rounded-2xl bg-blue-50/60 border-2 border-mimoo-dark/15 space-y-2">
                <div className="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-sm shadow-chunky-sm">
                  2
                </div>
                <h4 className="font-fredoka font-bold text-sm text-mimoo-dark">
                  Extrusão na Fazenda 3D
                </h4>
                <p className="text-xs font-nunito text-slate-600 leading-relaxed">
                  A peça é construída camada por camada (0.16mm a 0.20mm) com PLA biodegradável durante várias horas.
                </p>
              </div>

              <div className="p-5 rounded-2xl bg-emerald-50/60 border-2 border-mimoo-dark/15 space-y-2">
                <div className="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center font-bold text-sm shadow-chunky-sm">
                  3
                </div>
                <h4 className="font-fredoka font-bold text-sm text-mimoo-dark">
                  Acabamento & Despacho
                </h4>
                <p className="text-xs font-nunito text-slate-600 leading-relaxed">
                  Removemos suportes, aplicamos pós-processamento, embalamos com mimos e geramos o código de envio dos Correios.
                </p>
              </div>

            </div>
          </div>

        </div>
      )}

    </div>
  );
};
