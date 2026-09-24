import React, { useState } from 'react';
import { useShop } from '../context/ShopContext';
import { CATEGORY_LIST } from '../constants/categories';
import { Heart, Sparkles, Truck, ShieldCheck, Mail, ArrowRight, Share2 } from 'lucide-react';

export const Footer = () => {
  const { setActiveTab, setSelectedCategory, showToast } = useShop();
  const [emailInput, setEmailInput] = useState('');

  const handleNewsletter = (e) => {
    e.preventDefault();
    if (emailInput.trim()) {
      showToast('💌 Cupom MIMOO10 enviado para seu e-mail! Aproveite.');
      setEmailInput('');
    }
  };

  return (
    <footer className="bg-white border-t-2 border-mimoo-dark text-mimoo-dark">
      
      {/* Top Value Propositions */}
      <div className="border-b-2 border-mimoo-dark/10 bg-mimoo-purple-tint/50 py-8">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <div className="flex items-center gap-3.5 p-3 rounded-2xl bg-white border-2 border-mimoo-dark shadow-chunky-sm">
              <div className="w-10 h-10 rounded-xl bg-mimoo-yellow text-mimoo-dark flex items-center justify-center font-bold text-lg">
                ⏱️
              </div>
              <div className="text-xs font-fredoka">
                <strong className="block text-mimoo-dark">Feito Sob Encomenda</strong>
                <span className="text-slate-500">Impressão 3D personalizada</span>
              </div>
            </div>

            <div className="flex items-center gap-3.5 p-3 rounded-2xl bg-white border-2 border-mimoo-dark shadow-chunky-sm">
              <div className="w-10 h-10 rounded-xl bg-mimoo-mint-light text-emerald-700 flex items-center justify-center font-bold text-lg">
                🌱
              </div>
              <div className="text-xs font-fredoka">
                <strong className="block text-mimoo-dark">100% Biodegradável</strong>
                <span className="text-slate-500">Filamento PLA de milho & cana</span>
              </div>
            </div>

            <div className="flex items-center gap-3.5 p-3 rounded-2xl bg-white border-2 border-mimoo-dark shadow-chunky-sm">
              <div className="w-10 h-10 rounded-xl bg-mimoo-pink-light text-mimoo-pink-dark flex items-center justify-center font-bold text-lg">
                💖
              </div>
              <div className="text-xs font-fredoka">
                <strong className="block text-mimoo-dark">Garantia & Amor</strong>
                <span className="text-slate-500">Acabamento e cura impecáveis</span>
              </div>
            </div>

            <div className="flex items-center gap-3.5 p-3 rounded-2xl bg-white border-2 border-mimoo-dark shadow-chunky-sm">
              <div className="w-10 h-10 rounded-xl bg-mimoo-sky-light text-sky-700 flex items-center justify-center font-bold text-lg">
                🚚
              </div>
              <div className="text-xs font-fredoka">
                <strong className="block text-mimoo-dark">Rastreio em Tempo Real</strong>
                <span className="text-slate-500">Do bico extrusor à sua porta</span>
              </div>
            </div>

          </div>
        </div>
      </div>

      {/* Main Footer Links */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-8">
          
          {/* Brand Info */}
          <div className="lg:col-span-4 space-y-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 bg-mimoo-pink rounded-xl border-2 border-mimoo-dark p-1 shadow-chunky-sm flex items-center justify-center">
                <img 
                  src="/assets/logo-mascot.png" 
                  alt="Mascote Mimoo" 
                  className="w-full h-full object-contain"
                  onError={(e) => {
                    e.target.onerror = null;
                    e.target.src = '/assets/logo-full.png';
                  }}
                />
              </div>
              <span className="font-fredoka font-bold text-2xl text-mimoo-dark">
                MIMOO<span className="text-mimoo-purple">LAB</span>
              </span>
            </div>

            <p className="text-xs sm:text-sm text-slate-600 font-nunito leading-relaxed">
              Transformando filamentos ecológicos e ideias criativas em peças colecionáveis, decorativas e funcionais. Cada criação é impressa exclusivamente para você com muito carinho e precisão.
            </p>

            <div className="pt-2 flex items-center gap-2 text-xs font-fredoka font-bold text-slate-700">
              <span className="w-2.5 h-2.5 rounded-full bg-emerald-500" />
              <span>Fazenda 3D em Operação Contínua</span>
            </div>
          </div>

          {/* Quick Categories */}
          <div className="lg:col-span-2 space-y-3 font-fredoka text-xs sm:text-sm">
            <h4 className="font-bold text-mimoo-dark uppercase tracking-wider text-xs border-b border-slate-200 pb-2">
              Categorias
            </h4>
            <ul className="space-y-2 text-slate-600">
              {CATEGORY_LIST.map((cat) => (
                <li key={cat.slug}>
                  <button 
                    onClick={() => { setSelectedCategory(cat.slug); setActiveTab('catalog'); }}
                    className="hover:text-mimoo-purple transition-colors flex items-center gap-1.5"
                  >
                    <span>{cat.icon}</span>
                    <span>{cat.name}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>

          {/* Useful Links */}
          <div className="lg:col-span-2 space-y-3 font-fredoka text-xs sm:text-sm">
            <h4 className="font-bold text-mimoo-dark uppercase tracking-wider text-xs border-b border-slate-200 pb-2">
              Ajuda & Suporte
            </h4>
            <ul className="space-y-2 text-slate-600">
              <li>
                <button 
                  onClick={() => setActiveTab('tracking')}
                  className="hover:text-mimoo-purple font-bold text-mimoo-purple transition-colors"
                >
                  ⏱️ Rastrear Pedido 3D
                </button>
              </li>
              <li>
                <button 
                  onClick={() => setActiveTab('howItWorks')}
                  className="hover:text-mimoo-purple transition-colors"
                >
                  💡 Como Funciona
                </button>
              </li>
              <li>
                <a href="#faq" className="hover:text-mimoo-purple transition-colors">
                  ❓ Dúvidas & Cuidados PLA
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-mimoo-purple transition-colors">
                  🛡️ Política de Trocas
                </a>
              </li>
            </ul>
          </div>

          {/* Newsletter Box */}
          <div className="lg:col-span-4 space-y-3">
            <h4 className="font-fredoka font-bold text-mimoo-dark uppercase tracking-wider text-xs border-b border-slate-200 pb-2">
              Clube da Vaquinha Mimoo 🐮
            </h4>
            <p className="font-nunito text-xs text-slate-600">
              Receba novidades dos novos modelos 3D e ganhe <strong>10% OFF</strong> na sua primeira encomenda!
            </p>

            <form onSubmit={handleNewsletter} className="flex gap-2">
              <input
                type="email"
                required
                value={emailInput}
                onChange={(e) => setEmailInput(e.target.value)}
                placeholder="Seu melhor e-mail..."
                className="flex-1 rounded-2xl border-2 border-mimoo-dark px-3 py-2 text-xs font-nunito focus:outline-none"
              />
              <button
                type="submit"
                className="btn-chunky-purple px-4 py-2 text-xs font-bold flex items-center gap-1 shadow-chunky-sm"
              >
                <span>Inscrever</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </button>
            </form>

            <div className="pt-2 flex items-center gap-3 text-[10px] text-slate-400 font-nunito">
              <ShieldCheck className="w-4 h-4 text-emerald-600" />
              <span>Sem spam, só fofura e lançamentos exclusivos.</span>
            </div>
          </div>

        </div>

        {/* Bottom Copyright & Payment Methods */}
        <div className="mt-12 pt-6 border-t-2 border-mimoo-dark/10 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-nunito">
          <p>© 2026 MIMOO LAB Impressão 3D. Todos os direitos reservados. Feito com amor e PLA sustentável. 🐮💖</p>
          
          <div className="flex items-center gap-2 font-fredoka font-bold text-[11px] text-slate-700">
            <span className="bg-slate-100 px-2 py-0.5 rounded border">PIX (10% OFF)</span>
            <span className="bg-slate-100 px-2 py-0.5 rounded border">Cartões até 6x</span>
            <span className="bg-slate-100 px-2 py-0.5 rounded border">Boleto</span>
          </div>
        </div>

      </div>

    </footer>
  );
};

export default Footer;
