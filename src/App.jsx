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
import { LegalPage } from './components/LegalPage';
import { Sparkles, Clock, ArrowRight, CheckCircle, ShieldCheck } from 'lucide-react';

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
              subtitle="Peças fofas do nosso catálogo, prontas para serem impressas na sua cor favorita."
              showFilters={true}
              initialLimit={6}
            />

            {/* How It Works 4-Step Illustrated Section */}
            <HowItWorks />

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

        {activeTab === 'privacy' && (
          <LegalPage page="privacy" />
        )}

        {activeTab === 'terms' && (
          <LegalPage page="terms" />
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
