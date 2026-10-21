import React, { useState } from 'react';
import { useShop } from '../context/ShopContext';
import { CATEGORY_LIST } from '../constants/categories';
import { 
  Search, 
  ShoppingBag, 
  Heart, 
  User, 
  Truck, 
  Menu, 
  X
} from 'lucide-react';

export const Header = () => {
  const { 
    cartItemCount, 
    setIsCartOpen, 
    favorites, 
    activeTab, 
    setActiveTab, 
    setSelectedCategory,
    searchQuery,
    setSearchQuery,
    setIsAuthModalOpen,
    setAuthModalView,
    user
  } = useShop();

  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [isSearchFocused, setIsSearchFocused] = useState(false);

  const handleNav = (tab, category = 'all') => {
    setActiveTab(tab);
    setSelectedCategory(category);
    setMobileMenuOpen(false);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleSearchSubmit = (e) => {
    e.preventDefault();
    if (searchQuery.trim()) {
      setActiveTab('catalog');
      setMobileMenuOpen(false);
    }
  };

  return (
    <header className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b-2 border-mimoo-dark shadow-sm">
      {/* Top Banner Announcement Ticker */}
      <div className="bg-gradient-to-r from-mimoo-purple via-mimoo-pink to-mimoo-purple text-white py-1.5 px-4 text-xs md:text-sm font-fredoka tracking-wide flex items-center justify-between border-b border-mimoo-dark/20 overflow-hidden">
        <div className="flex items-center gap-2 mx-auto animate-pulse-subtle text-center">
          <span className="bg-white/20 px-2 py-0.5 rounded-full text-[11px] font-bold uppercase">✨ Sob Encomenda</span>
          <span>Peças impressas em 3D em filamento ecológico biodegradável • Frete calculado pelo CEP no carrinho</span>
        </div>
        <button 
          onClick={() => handleNav('tracking')}
          className="hidden lg:flex items-center gap-1 bg-white/20 hover:bg-white/30 transition-colors px-2.5 py-0.5 rounded-full text-xs font-semibold"
        >
          <Truck className="w-3.5 h-3.5" />
          <span>Rastrear Pedido</span>
        </button>
      </div>

      {/* Main Header Bar */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-20 gap-4">
          
          {/* Logo & Mascot */}
          <div 
            onClick={() => handleNav('home')}
            className="flex items-center gap-3 cursor-pointer group select-none flex-shrink-0"
          >
            <div className="relative w-12 h-12 md:w-14 md:h-14 bg-mimoo-pink-light rounded-2xl border-2 border-mimoo-dark p-1 shadow-chunky-sm group-hover:rotate-6 transition-transform flex items-center justify-center overflow-hidden">
              <img 
                src="/assets/logo-mascot.png" 
                alt="MIMOO LAB Mascote Vaquinha" 
                className="w-full h-full object-contain group-hover:scale-110 transition-transform"
                onError={(e) => {
                  e.target.onerror = null;
                  e.target.src = '/assets/logo-full.png';
                }}
              />
            </div>
            {/* No celular (< 640px) só o mascote: o nome não cabe junto dos quatro botões */}
            <div className="hidden sm:flex flex-col">
              <div className="flex items-center gap-1.5">
                <span className="font-fredoka font-bold text-2xl md:text-3xl text-mimoo-dark tracking-tight leading-none group-hover:text-mimoo-purple transition-colors">
                  MIMOO<span className="text-mimoo-purple ml-1">LAB</span>
                </span>
                <span className="hidden sm:inline-block bg-mimoo-yellow text-mimoo-dark text-[10px] font-fredoka font-bold px-1.5 py-0.5 rounded-md border border-mimoo-dark">
                  3D STORE
                </span>
              </div>
              <span className="text-[11px] font-nunito font-semibold text-slate-500 tracking-wider">
                Impressões 3D Sob Encomenda
              </span>
            </div>
          </div>

          {/* Search Bar - Desktop */}
          <div className="hidden md:flex flex-1 max-w-md mx-4">
            <form onSubmit={handleSearchSubmit} className="relative w-full">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onFocus={() => setIsSearchFocused(true)}
                onBlur={() => setIsSearchFocused(false)}
                placeholder="Buscar vasos, suportes, articulados..."
                className={`w-full rounded-2xl border-2 border-mimoo-dark bg-slate-50 pl-11 pr-4 py-2.5 text-sm font-nunito transition-all duration-200 focus:outline-none focus:bg-white ${
                  isSearchFocused ? 'shadow-chunky-sm border-mimoo-purple ring-2 ring-mimoo-purple/20' : ''
                }`}
              />
              <Search className="w-5 h-5 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-mimoo-dark"
                >
                  <X className="w-4 h-4" />
                </button>
              )}
            </form>
          </div>

          {/* Action Icons Right */}
          <div className="flex items-center gap-2 sm:gap-3">
            {/* Tracking Shortcut (Desktop) */}
            <button
              onClick={() => handleNav('tracking')}
              className={`hidden sm:flex items-center gap-1.5 px-3 py-2 rounded-2xl border-2 border-mimoo-dark transition-all text-xs font-fredoka font-bold ${
                activeTab === 'tracking' 
                  ? 'bg-mimoo-yellow text-mimoo-dark shadow-chunky-sm' 
                  : 'bg-white text-slate-700 hover:bg-mimoo-yellow-light shadow-chunky-sm'
              }`}
              title="Acompanhe sua fabricação em tempo real"
            >
              <Truck className="w-4 h-4 text-mimoo-purple" />
              <span>Rastrear 3D</span>
            </button>

            {/* Favorites Icon */}
            <button
              onClick={() => handleNav('favorites')}
              className={`relative p-2.5 rounded-2xl border-2 border-mimoo-dark transition-all shadow-chunky-sm ${
                activeTab === 'favorites' ? 'bg-mimoo-pink text-white' : 'bg-white hover:bg-mimoo-pink-light'
              }`}
              title="Seus favoritos"
            >
              <Heart className={`w-5 h-5 ${activeTab === 'favorites' ? 'text-white fill-white' : 'text-slate-700'}`} />
              {favorites.length > 0 && (
                <span className="absolute -top-1.5 -right-1.5 bg-mimoo-pink text-white text-[10px] font-bold rounded-full w-5 h-5 flex items-center justify-center border-2 border-mimoo-dark font-fredoka">
                  {favorites.length}
                </span>
              )}
            </button>

            {/* Conta do cliente (M-26): também no celular */}
            <button
              type="button"
              onClick={() => {
                if (!user.isLoggedIn) setAuthModalView('login');
                setIsAuthModalOpen(true);
              }}
              className="flex items-center p-2.5 rounded-2xl border-2 border-mimoo-dark bg-white hover:bg-slate-50 transition-all shadow-chunky-sm"
              title={user.isLoggedIn ? `Olá, ${user.name}` : 'Entrar ou Criar Conta'}
              aria-label={user.isLoggedIn ? 'Minha conta' : 'Entrar ou criar conta'}
            >
              <User className="w-5 h-5 text-slate-700" aria-hidden="true" />
            </button>

            {/* Cart Button with Bounce */}
            <button
              onClick={() => setIsCartOpen(true)}
              className="relative btn-chunky-purple px-3.5 py-2.5 sm:px-4 sm:py-2.5 flex items-center gap-2 shadow-chunky"
              title="Ver sacola de mimos"
            >
              <ShoppingBag className="w-5 h-5" />
              <span className="hidden sm:inline font-fredoka text-sm">Sacola</span>
              <span className="bg-mimoo-yellow text-mimoo-dark font-black text-xs px-2 py-0.5 rounded-full border border-mimoo-dark font-fredoka">
                {cartItemCount}
              </span>
            </button>

            {/* Mobile Menu Toggle */}
            <button
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="md:hidden p-2.5 rounded-2xl border-2 border-mimoo-dark bg-white shadow-chunky-sm"
              aria-label="Abrir Menu"
            >
              {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
            </button>
          </div>
        </div>

        {/* Navigation Categories Bar - Desktop */}
        <nav className="hidden md:flex items-center justify-between border-t border-slate-200 py-2.5 text-sm font-fredoka">
          <div className="flex items-center gap-1 lg:gap-2">
            <button
              onClick={() => handleNav('home')}
              className={`px-3 py-1.5 rounded-xl font-medium transition-all ${
                activeTab === 'home' 
                  ? 'bg-mimoo-purple text-white shadow-chunky-sm border-2 border-mimoo-dark font-bold' 
                  : 'text-slate-600 hover:text-mimoo-purple hover:bg-mimoo-purple-tint'
              }`}
            >
              Início
            </button>

            <button
              onClick={() => handleNav('catalog', 'all')}
              className={`px-3 py-1.5 rounded-xl font-medium transition-all ${
                activeTab === 'catalog' 
                  ? 'bg-mimoo-purple text-white shadow-chunky-sm border-2 border-mimoo-dark font-bold' 
                  : 'text-slate-600 hover:text-mimoo-purple hover:bg-mimoo-purple-tint'
              }`}
            >
              Catálogo Completo
            </button>

            {CATEGORY_LIST.map((cat) => (
              <button
                key={cat.slug}
                onClick={() => handleNav('catalog', cat.slug)}
                className="px-3 py-1.5 rounded-xl text-slate-600 hover:text-mimoo-purple hover:bg-mimoo-purple-tint transition-all flex items-center gap-1.5"
              >
                <span>{cat.icon}</span>
                <span>{cat.name}</span>
              </button>
            ))}

            <button
              onClick={() => handleNav('howItWorks')}
              className={`px-3 py-1.5 rounded-xl font-medium transition-all ${
                activeTab === 'howItWorks' 
                  ? 'bg-mimoo-yellow text-mimoo-dark shadow-chunky-sm border-2 border-mimoo-dark font-bold' 
                  : 'text-slate-600 hover:text-mimoo-purple hover:bg-mimoo-yellow-light'
              }`}
            >
              💡 Como Funciona
            </button>
          </div>

          <div className="flex items-center gap-2 text-xs text-slate-500 font-semibold">
            <span className="flex items-center gap-1 bg-mimoo-mint-light text-emerald-800 px-2.5 py-1 rounded-full border border-emerald-300 font-fredoka">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-ping" />
              Fazenda 3D Operando
            </span>
          </div>
        </nav>
      </div>

      {/* Mobile Drawer Menu */}
      {mobileMenuOpen && (
        <div className="md:hidden border-t-2 border-mimoo-dark bg-white p-4 space-y-4 shadow-xl animate-in slide-in-from-top duration-200">
          <form onSubmit={handleSearchSubmit} className="relative">
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="O que deseja imprimir hoje?"
              className="w-full rounded-2xl border-2 border-mimoo-dark bg-slate-50 pl-11 pr-4 py-2.5 text-sm font-nunito focus:outline-none"
            />
            <Search className="w-5 h-5 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          </form>

          <div className="grid grid-cols-2 gap-2 font-fredoka text-sm">
            <button
              onClick={() => handleNav('home')}
              className="p-3 text-left rounded-2xl border-2 border-mimoo-dark bg-mimoo-purple-tint font-bold text-mimoo-purple"
            >
              🏠 Início
            </button>
            <button
              onClick={() => handleNav('catalog', 'all')}
              className="p-3 text-left rounded-2xl border-2 border-mimoo-dark bg-slate-50 font-bold text-slate-800"
            >
              🛍️ Catálogo Geral
            </button>
            {CATEGORY_LIST.map((cat) => (
              <button
                key={cat.slug}
                onClick={() => handleNav('catalog', cat.slug)}
                className="p-3 text-left rounded-2xl border border-slate-200 bg-white text-slate-700 flex items-center gap-1.5"
              >
                <span>{cat.icon}</span>
                <span>{cat.name}</span>
              </button>
            ))}
            <button
              onClick={() => handleNav('tracking')}
              className="p-3 text-left rounded-2xl border-2 border-mimoo-dark bg-mimoo-yellow text-mimoo-dark font-bold"
            >
              ⏱️ Rastrear Pedido
            </button>
          </div>

          <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500 font-medium">
            <span>MIMOO LAB • Feito Sob Encomenda</span>
            <button 
              onClick={() => handleNav('howItWorks')}
              className="text-mimoo-purple font-bold underline"
            >
              Dúvidas Frequentes
            </button>
          </div>
        </div>
      )}
    </header>
  );
};

export default Header;
