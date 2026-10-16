import { CartProvider } from './context/CartContext';
import { ShopProvider, useShop } from './context/ShopContext';
import { Header } from './components/Header';
import { Hero } from './components/Hero';
import { LiveFarmTicker } from './components/LiveFarmTicker';
import { HowItWorks } from './components/HowItWorks';
import { ProductGrid } from './components/ProductGrid';
import { ProductDetailModal } from './components/ProductDetailModal';
import { TrackingView } from './components/TrackingView';
import { CartDrawer } from './components/CartDrawer';
import { CheckoutModal } from './components/checkout/CheckoutModal';
import { AuthModal } from './components/AuthModal';
import { FAQSection } from './components/FAQSection';
import { Footer } from './components/Footer';
import { Sparkles, Heart, Clock, ArrowRight, CheckCircle, ShieldCheck } from 'lucide-react';

const MainContent = () => {
  const { activeTab, setActiveTab, toast } = useShop();

  return (
    <div className="min-h-screen flex flex-col justify-between relative bg-mimoo-bg">
      
      {/* Global Toast Notification */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 animate-in slide-in-from-bottom-5 duration-300">
          <div className={`p-4 rounded-2xl border-2 border-mimoo-dark shadow-chunky text-xs sm:text-sm font-fredoka flex items-center gap-2.5 max-w-sm ${
            toast.type === 'info' 
              ? 'bg-white text-slate-800' 
              : 'bg-mimoo-purple text-white'
          }`}>
            <span className="text-base">🐮</span>
            <span className="font-semibold">{toast.message}</span>
          </div>
        </div>
      )}

      {/* Header */}
      <Header />

      {/* Live 3D Printing Farm Ticker */}
      <LiveFarmTicker />

      {/* Main Routed Views */}
      <main className="flex-1">
        {activeTab === 'home' && (
          <div>
            {/* Hero Section */}
            <Hero />

            {/* Featured Best Sellers Section */}
            <ProductGrid 
              title="🌟 Destaques Feitos Sob Encomenda" 
              subtitle="As peças mais amadas pelos nossos clientes, prontas para serem impressas na sua cor favorita."
              showFilters={true}
              initialLimit={6}
            />

            {/* How It Works 4-Step Illustrated Section */}
            <HowItWorks />

            {/* Customer Reviews Spotlight */}
            <section className="py-14 sm:py-20 bg-mimoo-purple-tint/60 border-b-2 border-mimoo-dark/10">
              <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">
                <div className="text-center space-y-3">
                  <div className="inline-flex items-center gap-1.5 bg-mimoo-pink-light text-mimoo-pink-dark font-fredoka text-xs font-bold px-3 py-1 rounded-full border border-pink-200">
                    <Heart className="w-3.5 h-3.5 fill-pink-500 text-pink-500" />
                    <span>Mimos em Ação</span>
                  </div>
                  <h2 className="font-fredoka font-bold text-3xl sm:text-4xl text-mimoo-dark">
                    Amados por Mais de 1.400 Clientes
                  </h2>
                  <p className="font-nunito text-slate-600 text-sm sm:text-base max-w-xl mx-auto">
                    Veja o que quem já encomendou diz sobre a qualidade das camadas, o cheirinho dos pacotes e o tempo de entrega!
                  </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                  <div className="card-chunky p-6 bg-white space-y-3">
                    <div className="flex items-center gap-1 text-amber-400 font-bold text-sm">
                      ⭐⭐⭐⭐⭐
                    </div>
                    <p className="font-nunito text-xs sm:text-sm text-slate-700 leading-relaxed italic">
                      "O Vasinho da vaquinha em Lilás ficou a coisa mais perfeita da minha mesa! As linhas de impressão são tão suaves que quase não dá pra ver. Chegou muito antes do prazo estimado!"
                    </p>
                    <div className="flex items-center gap-3 pt-2 border-t border-slate-100">
                      <div className="w-9 h-9 rounded-full bg-mimoo-pink text-white font-fredoka font-bold flex items-center justify-center border border-mimoo-dark">
                        C
                      </div>
                      <div>
                        <strong className="font-fredoka text-xs text-mimoo-dark block">Camila Silveira</strong>
                        <span className="text-[10px] text-slate-400 font-nunito">Comprou: Vasinho Mimoo Vaquinha</span>
                      </div>
                    </div>
                  </div>

                  <div className="card-chunky p-6 bg-white space-y-3">
                    <div className="flex items-center gap-1 text-amber-400 font-bold text-sm">
                      ⭐⭐⭐⭐⭐
                    </div>
                    <p className="font-nunito text-xs sm:text-sm text-slate-700 leading-relaxed italic">
                      "O suporte do gatinho dorminhoco é extremamente firme e bem acabado. O painel de rastreamento com a simulação da impressora é viciante! Acompanhei cada etapa."
                    </p>
                    <div className="flex items-center gap-3 pt-2 border-t border-slate-100">
                      <div className="w-9 h-9 rounded-full bg-mimoo-purple text-white font-fredoka font-bold flex items-center justify-center border border-mimoo-dark">
                        L
                      </div>
                      <div>
                        <strong className="font-fredoka text-xs text-mimoo-dark block">Lucas Medeiros</strong>
                        <span className="text-[10px] text-slate-400 font-nunito">Comprou: Suporte Gatinho Dorminhoco</span>
                      </div>
                    </div>
                  </div>

                  <div className="card-chunky p-6 bg-white space-y-3">
                    <div className="flex items-center gap-1 text-amber-400 font-bold text-sm">
                      ⭐⭐⭐⭐⭐
                    </div>
                    <p className="font-nunito text-xs sm:text-sm text-slate-700 leading-relaxed italic">
                      "O dragão articulado silk é uma obra de arte! Fiquei impressionada que as articulações já saíram prontas da impressora. Embalagem impecável com adesivos lindos."
                    </p>
                    <div className="flex items-center gap-3 pt-2 border-t border-slate-100">
                      <div className="w-9 h-9 rounded-full bg-mimoo-yellow text-mimoo-dark font-fredoka font-bold flex items-center justify-center border border-mimoo-dark">
                        B
                      </div>
                      <div>
                        <strong className="font-fredoka text-xs text-mimoo-dark block">Beatriz Fontana</strong>
                        <span className="text-[10px] text-slate-400 font-nunito">Comprou: Dragão Articulado Silk</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            {/* FAQ Section */}
            <FAQSection />
          </div>
        )}

        {activeTab === 'catalog' && (
          <div className="py-6">
            <ProductGrid 
              title="🛍️ Catálogo Geral de Criações 3D"
              subtitle="Filtre por categoria, tempo estimado de impressão ou faixa de preço."
              showFilters={true}
            />
          </div>
        )}

        {activeTab === 'tracking' && (
          <TrackingView />
        )}

        {activeTab === 'howItWorks' && (
          <div className="space-y-6">
            <HowItWorks />
            <FAQSection />
          </div>
        )}
      </main>

      {/* Global Modals & Drawers */}
      <ProductDetailModal />
      <CartDrawer />
      <CheckoutModal />
      <AuthModal />

      {/* Footer */}
      <Footer />

    </div>
  );
};

export default function App() {
  return (
    <CartProvider>
      <ShopProvider>
        <MainContent />
      </ShopProvider>
    </CartProvider>
  );
}
