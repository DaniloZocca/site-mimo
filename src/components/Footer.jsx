import React from 'react';
import { useShop } from '../context/ShopContext';
import { CATEGORY_LIST } from '../constants/categories';
import { LEGAL_LINKS } from '../content/legal';
import { LOJA } from '../config/loja';
import { Heart, Sparkles, Truck, Mail, ArrowRight, Share2 } from 'lucide-react';

export const Footer = () => {
  const { setActiveTab, setSelectedCategory } = useShop();

  const openLegalPage = (tab) => {
    setActiveTab(tab);
    window.scrollTo({ top: 0, behavior: 'smooth' });
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
                <strong className="block text-mimoo-dark">Compostável na Indústria</strong>
                <span className="text-slate-500">Filamento PLA de origem vegetal</span>
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
                <strong className="block text-mimoo-dark">Rastreio de Cada Etapa</strong>
                <span className="text-slate-500">Da impressora à sua porta</span>
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
              Transformando filamento PLA e ideias criativas em peças colecionáveis, decorativas e funcionais. Cada criação é impressa exclusivamente para você com muito carinho e precisão.
            </p>
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
                <a href={`${LEGAL_LINKS.terms}#trocas`} className="hover:text-mimoo-purple transition-colors">
                  🛡️ Política de Trocas
                </a>
              </li>
              <li>
                <button 
                  onClick={() => openLegalPage('terms')}
                  className="hover:text-mimoo-purple transition-colors"
                >
                  📜 Termos de Uso
                </button>
              </li>
              <li>
                <button 
                  onClick={() => openLegalPage('privacy')}
                  className="hover:text-mimoo-purple transition-colors"
                >
                  🔒 Política de Privacidade
                </button>
              </li>
            </ul>
          </div>

          {/* Fale com a gente */}
          <div className="lg:col-span-4 space-y-3">
            <h4 className="font-fredoka font-bold text-mimoo-dark uppercase tracking-wider text-xs border-b border-slate-200 pb-2">
              Fale com a gente 🐮
            </h4>
            <p className="font-nunito text-xs text-slate-600">
              Atendimento {LOJA.horarioAtendimento}.
            </p>

            <div className="flex flex-wrap gap-2">
              <a
                href={`https://wa.me/${LOJA.whatsapp}`}
                target="_blank"
                rel="noopener noreferrer"
                className="btn-chunky-purple px-4 py-2 text-xs font-bold flex items-center gap-1 shadow-chunky-sm"
              >
                <span>💬 WhatsApp {LOJA.whatsappExibicao}</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </a>
              <a
                href={`https://www.instagram.com/${LOJA.instagram}/`}
                target="_blank"
                rel="noopener noreferrer"
                className="btn-chunky-white px-4 py-2 text-xs font-bold flex items-center gap-1"
              >
                <span>📸 @{LOJA.instagram}</span>
              </a>
            </div>

            <div className="pt-2 flex items-center gap-3 text-xs text-slate-600 font-nunito">
              <Mail className="w-4 h-4 text-mimoo-purple" />
              <a href={`mailto:${LOJA.emailContato}`} className="hover:text-mimoo-purple transition-colors">{LOJA.emailContato}</a>
            </div>
          </div>

        </div>

        {/* Bottom Copyright & Payment Methods */}
        <div className="mt-12 pt-6 border-t-2 border-mimoo-dark/10 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-nunito">
          <p>© 2026 MIMOO LAB Impressão 3D. Todos os direitos reservados. Feito com amor e PLA. 🐮💖</p>
          
          <div className="flex items-center gap-2 font-fredoka font-bold text-[11px] text-slate-700">
            <span className="bg-slate-100 px-2 py-0.5 rounded border">PIX (10% OFF)</span>
            <span className="bg-slate-100 px-2 py-0.5 rounded border">Cartões até 6x</span>
          </div>
        </div>

      </div>

    </footer>
  );
};

export default Footer;
