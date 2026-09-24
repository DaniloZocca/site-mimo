import React from 'react';
import { Palette, Cpu, Sparkles, Gift, ArrowRight, Clock, ShieldCheck, Heart } from 'lucide-react';
import { useShop } from '../context/ShopContext';

export const HowItWorks = () => {
  const { setActiveTab } = useShop();

  const steps = [
    {
      step: '01',
      title: 'Você Escolhe o Modelo & Cor',
      desc: 'Navegue pelo nosso catálogo de criações exclusivas, selecione o tamanho e a cor do filamento PLA que mais combina com seu setup.',
      icon: Palette,
      color: 'bg-mimoo-purple-tint text-mimoo-purple border-mimoo-purple',
      badge: 'Personalização Total'
    },
    {
      step: '02',
      title: 'Nossa Impressora 3D Entra em Ação',
      desc: 'Assim que o pedido é aprovado, a impressora aquece a 210°C e deposita o filamento camada por camada (0.12mm) com precisão milimétrica.',
      icon: Cpu,
      color: 'bg-mimoo-pink-light text-mimoo-pink-dark border-mimoo-pink',
      badge: 'Tempo de Máquina: 8h a 24h'
    },
    {
      step: '03',
      title: 'Acabamento Artesanal & Inspeção',
      desc: 'Nossa equipe inspeciona cada detalhe, remove delicadamente qualquer suporte e aplica o acabamento suave para um toque sedoso.',
      icon: Sparkles,
      color: 'bg-mimoo-yellow-light text-amber-800 border-amber-400',
      badge: 'Controle de Qualidade'
    },
    {
      step: '04',
      title: 'Embalado com Mimos & Enviado',
      desc: 'Sua peça é protegida em papel de seda especial, ganha adesivos colecionáveis da vaquinha e vai direto para a transportadora!',
      icon: Gift,
      color: 'bg-mimoo-mint-light text-emerald-800 border-emerald-400',
      badge: 'Rastreio em Tempo Real'
    }
  ];

  return (
    <section className="py-14 sm:py-20 bg-white border-b-2 border-mimoo-dark/10 relative overflow-hidden">
      
      {/* Background Dots */}
      <div className="absolute inset-0 bg-dot-pattern opacity-40 pointer-events-none" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        {/* Section Header */}
        <div className="text-center max-w-2xl mx-auto mb-12 sm:mb-16 space-y-3">
          <div className="inline-flex items-center gap-2 bg-mimoo-yellow text-mimoo-dark font-fredoka text-xs font-bold px-3 py-1 rounded-full border-2 border-mimoo-dark shadow-chunky-sm">
            <span>✨ Magia Sob Encomenda</span>
          </div>

          <h2 className="font-fredoka font-bold text-3xl sm:text-4xl text-mimoo-dark tracking-tight">
            Como Funciona a MIMOO LAB?
          </h2>

          <p className="font-nunito text-slate-600 text-sm sm:text-base">
            Diferente de estoques convencionais, criamos cada item <strong>exclusivamente para você</strong>. Isso garante zero desperdício de plástico e acabamento perfeito!
          </p>
        </div>

        {/* 4 Steps Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 relative">
          
          {steps.map((s, index) => {
            const Icon = s.icon;
            return (
              <div 
                key={s.step}
                className="card-chunky p-6 flex flex-col justify-between relative group hover:-translate-y-1.5 transition-transform"
              >
                {/* Step Number Top Badge */}
                <div className="flex items-center justify-between mb-4">
                  <div className={`w-12 h-12 rounded-2xl border-2 border-mimoo-dark ${s.color} flex items-center justify-center shadow-chunky-sm group-hover:scale-110 transition-transform`}>
                    <Icon className="w-6 h-6" />
                  </div>
                  <span className="font-fredoka font-bold text-3xl text-slate-200 group-hover:text-mimoo-purple/30 transition-colors">
                    {s.step}
                  </span>
                </div>

                <div className="space-y-2 mb-4">
                  <h3 className="font-fredoka font-bold text-lg text-mimoo-dark leading-snug">
                    {s.title}
                  </h3>
                  <p className="font-nunito text-xs sm:text-sm text-slate-500 leading-relaxed">
                    {s.desc}
                  </p>
                </div>

                <div className="pt-3 border-t border-slate-100">
                  <span className="text-[11px] font-fredoka font-semibold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200 block text-center">
                    {s.badge}
                  </span>
                </div>
              </div>
            );
          })}

        </div>

        {/* Eco Callout Banner */}
        <div className="mt-12 bg-gradient-to-r from-mimoo-purple via-indigo-700 to-mimoo-purple text-white rounded-3xl border-2 border-mimoo-dark p-6 sm:p-8 shadow-chunky flex flex-col md:flex-row items-center justify-between gap-6">
          <div className="flex items-center gap-4 text-center md:text-left">
            <div className="w-14 h-14 rounded-2xl bg-white/10 border-2 border-white/30 flex items-center justify-center flex-shrink-0 text-2xl">
              🌱
            </div>
            <div>
              <h4 className="font-fredoka font-bold text-xl sm:text-2xl text-white">
                Por que a Impressão Sob Demanda é o Futuro?
              </h4>
              <p className="font-nunito text-xs sm:text-sm text-purple-100 max-w-xl mt-1">
                Zero estoques acumulados e plástico jogado fora. Cada grama de filamento PLA é aquecida e moldada com destino certo: alegrar o seu dia!
              </p>
            </div>
          </div>

          <button
            onClick={() => setActiveTab('catalog')}
            className="btn-chunky-yellow px-6 py-3 text-sm flex items-center gap-2 flex-shrink-0"
          >
            <span>Escolher Meu Modelo</span>
            <ArrowRight className="w-4 h-4" />
          </button>
        </div>

      </div>

    </section>
  );
};
