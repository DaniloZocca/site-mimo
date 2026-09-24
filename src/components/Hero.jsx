import React from 'react';
import { useShop } from '../context/ShopContext';
import { Sparkles, ArrowRight, Truck, ShieldCheck, Heart, Clock, Play } from 'lucide-react';

export const Hero = () => {
  const { setActiveTab, setSelectedCategory, products, openProductDetail } = useShop();

  const handleExplore = (category = 'all') => {
    setSelectedCategory(category);
    setActiveTab('catalog');
  };

  const featured = products && products.length > 0 ? products[0] : null;

  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-mimoo-purple-tint/80 via-white to-mimoo-bg py-12 md:py-20 border-b-2 border-mimoo-dark/10">
      
      {/* Decorative Polka Dot and Background Accents */}
      <div className="absolute inset-0 bg-dot-pattern opacity-60 pointer-events-none" />
      <div className="absolute top-10 left-1/4 w-72 h-72 bg-mimoo-pink/15 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute bottom-10 right-1/4 w-80 h-80 bg-mimoo-purple/15 rounded-full blur-3xl pointer-events-none" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-8 items-center">
          
          {/* Left Column: Headline & CTAs */}
          <div className="lg:col-span-7 space-y-6 text-center lg:text-left">
            
            {/* Top Pill Alert */}
            <div className="inline-flex items-center gap-2 bg-white px-3.5 py-1.5 rounded-full border-2 border-mimoo-dark shadow-chunky-sm text-xs md:text-sm font-fredoka text-mimoo-purple font-semibold animate-float">
              <span className="w-2 h-2 rounded-full bg-mimoo-pink animate-ping" />
              <span>🐮 Feito Sob Encomenda • Sem Estoque Parado!</span>
            </div>

            {/* Main Headline */}
            <h1 className="font-fredoka font-bold text-4xl sm:text-5xl lg:text-6xl text-mimoo-dark leading-[1.12] tracking-tight">
              Impressões 3D que dão{' '}
              <span className="text-mimoo-purple">
                vida e fofura
              </span>{' '}
              ao seu cantinho ✨
            </h1>

            {/* Subheading */}
            <p className="font-nunito text-base sm:text-lg text-slate-600 max-w-xl mx-auto lg:mx-0 leading-relaxed">
              Decoração, suportes geek e peças colecionáveis fabricadas em <strong>filamento PLA ecológico</strong> com alta definição. Escolha sua cor favorita e veja sua encomenda nascer na impressora!
            </p>

            {/* Highlight Badges Row */}
            <div className="flex flex-wrap items-center justify-center lg:justify-start gap-2.5 pt-1 text-xs font-fredoka">
              <span className="badge-chunky bg-mimoo-yellow text-mimoo-dark flex items-center gap-1">
                <Clock className="w-3.5 h-3.5 text-mimoo-purple" />
                Tempo de Impressão Transparente
              </span>
              <span className="badge-chunky bg-mimoo-mint-light text-emerald-800 border-emerald-400 flex items-center gap-1">
                🌱 100% PLA Biodegradável
              </span>
              <span className="badge-chunky bg-mimoo-pink-light text-pink-700 border-pink-300 flex items-center gap-1">
                🎨 8+ Cores Pastel & Seda
              </span>
            </div>

            {/* Chunky Action Buttons */}
            <div className="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3.5 pt-4">
              <button
                onClick={() => handleExplore('all')}
                className="w-full sm:w-auto btn-chunky-purple px-8 py-4 text-base sm:text-lg flex items-center justify-center gap-2 shadow-chunky"
              >
                <Sparkles className="w-5 h-5 text-mimoo-yellow" />
                <span>Explorar Criações</span>
                <ArrowRight className="w-5 h-5" />
              </button>

              <button
                onClick={() => setActiveTab('tracking')}
                className="w-full sm:w-auto btn-chunky-white px-6 py-4 text-sm sm:text-base flex items-center justify-center gap-2"
              >
                <Truck className="w-4 h-4 text-mimoo-purple" />
                <span>Rastrear Encomenda 3D</span>
              </button>
            </div>

            {/* Quick Stat Social Proof */}
            <div className="pt-4 flex items-center justify-center lg:justify-start gap-6 text-xs text-slate-500 font-nunito border-t border-slate-200/80">
              <div className="flex items-center gap-2">
                <div className="flex -space-x-2">
                  <div className="w-7 h-7 rounded-full bg-mimoo-pink text-white font-fredoka font-bold flex items-center justify-center border-2 border-white text-[10px]">C</div>
                  <div className="w-7 h-7 rounded-full bg-mimoo-purple text-white font-fredoka font-bold flex items-center justify-center border-2 border-white text-[10px]">M</div>
                  <div className="w-7 h-7 rounded-full bg-mimoo-yellow text-mimoo-dark font-fredoka font-bold flex items-center justify-center border-2 border-white text-[10px]">B</div>
                </div>
                <span><strong>+1.400</strong> mimos impressos e entregues</span>
              </div>
              <div className="flex items-center gap-1 font-semibold text-amber-500">
                <span>⭐ 4.9/5.0 de avaliação</span>
              </div>
            </div>

          </div>

          {/* Right Column: Hero Visual & Interactive Showcase Card */}
          <div className="lg:col-span-5 relative">
            
            {/* Main Showcase Image Frame */}
            <div className="relative mx-auto max-w-md lg:max-w-none">
              
              {/* Backing decorative colored card */}
              <div className="absolute inset-0 bg-mimoo-pink rounded-4xl border-2 border-mimoo-dark transform rotate-3 scale-[1.02] shadow-chunky" />

              {/* Main Card */}
              <div className="relative bg-white rounded-4xl border-2 border-mimoo-dark shadow-chunky overflow-hidden p-3 sm:p-4">
                
                {/* Product Image */}
                <div className="relative rounded-3xl overflow-hidden border-2 border-mimoo-dark aspect-[4/3] bg-slate-100">
                  <img 
                    src={featured ? featured.image : "/assets/hero_showcase.jpg"} 
                    alt={featured ? featured.name : "MIMOO LAB Impressão 3D Studio"}
                    className="w-full h-full object-cover"
                    onError={(e) => {
                      e.target.onerror = null;
                      e.target.src = '/assets/products/cow_planter.jpg';
                    }}
                  />

                  {/* Floating Top Badge */}
                  <div className="absolute top-3 left-3 bg-white/95 backdrop-blur-sm border-2 border-mimoo-dark rounded-full px-3 py-1 text-xs font-fredoka font-bold text-mimoo-dark shadow-chunky-sm flex items-center gap-1.5">
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
                    <span>Fazenda 3D Imprimindo</span>
                  </div>

                  {/* Floating Layer Status overlay */}
                  <div className="absolute bottom-3 right-3 bg-mimoo-dark/85 backdrop-blur-md text-white rounded-2xl p-2.5 border border-white/20 text-xs font-fredoka shadow-lg max-w-[200px]">
                    <div className="flex items-center justify-between gap-2 text-[10px] text-slate-300">
                      <span>Bico: 210°C</span>
                      <span className="text-mimoo-pink">Camada 748/1100</span>
                    </div>
                    <div className="w-full bg-white/20 h-1.5 rounded-full mt-1 overflow-hidden">
                      <div className="bg-gradient-to-r from-mimoo-pink to-mimoo-purple h-full w-[68%]" />
                    </div>
                  </div>
                </div>

                {/* Card Bottom Meta */}
                <div className="pt-3.5 px-1 flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <div className="w-10 h-10 rounded-2xl border-2 border-mimoo-dark bg-mimoo-purple-tint p-1 flex items-center justify-center">
                      <img 
                        src="/assets/logo-mascot.png" 
                        alt="Vaquinha Mimoo" 
                        className="w-full h-full object-contain"
                      />
                    </div>
                    <div>
                      <h4 className="font-fredoka font-bold text-sm text-mimoo-dark">
                        {featured ? featured.name : 'Criação MIMOO Especial'}
                      </h4>
                      <span className="text-xs text-slate-500 font-nunito">
                        {featured ? `Tempo de Impressão: ~${featured.printTimeHours} horas` : 'Feito Sob Encomenda'}
                      </span>
                    </div>
                  </div>

                  <button
                    onClick={() => featured ? openProductDetail(featured) : handleExplore('all')}
                    className="btn-chunky-pink px-3.5 py-2 text-xs flex items-center gap-1 font-bold"
                  >
                    <span>Ver Peça</span>
                    <ArrowRight className="w-3.5 h-3.5" />
                  </button>
                </div>

              </div>

              {/* Floating Floating Mascot Sticker */}
              <div className="absolute -bottom-6 -left-6 bg-mimoo-yellow rounded-3xl border-2 border-mimoo-dark p-3 shadow-chunky hidden sm:flex items-center gap-2.5 animate-bounce-soft">
                <span className="text-xl">🐮</span>
                <div className="text-xs font-fredoka">
                  <strong className="block text-mimoo-dark">100% Personalizável</strong>
                  <span className="text-slate-700 font-medium">Você escolhe as cores!</span>
                </div>
              </div>

            </div>

          </div>

        </div>
      </div>

    </section>
  );
};
