import React, { useState, useMemo } from 'react';
import { useShop } from '../context/ShopContext';
import { ProductCard } from './ProductCard';
import { CATEGORIES, PRODUCTS } from '../data/mockProducts';
import { getCategoryMeta } from '../constants/categories';
import { Sparkles, Clock, ArrowUpDown, RotateCcw } from 'lucide-react';

export const ProductGrid = ({ title, subtitle, showFilters = true, initialLimit = null }) => {
  const { 
    products,
    selectedCategory, 
    setSelectedCategory, 
    searchQuery, 
    setSearchQuery 
  } = useShop();

  const productList = products || PRODUCTS;
  const [timeFilter, setTimeFilter] = useState('all'); // 'all' | 'fast' (<12h) | 'standard' (12-24h) | 'complex' (>24h)
  const [sortBy, setSortBy] = useState('popular'); // 'popular' | 'price-asc' | 'price-desc' | 'time-asc'

  // Filter and sort logic com suporte multi-categoria
  const filteredProducts = useMemo(() => {
    if (!selectedCategory || selectedCategory === 'all') {
      return productList.filter((product) => {
        if (searchQuery.trim()) {
          const q = searchQuery.toLowerCase();
          const matchName = product.name?.toLowerCase().includes(q);
          const matchDesc = product.shortDescription?.toLowerCase().includes(q) || product.fullDescription?.toLowerCase().includes(q);
          const matchCat = product.categoryName?.toLowerCase().includes(q) ||
            (Array.isArray(product.categories) && product.categories.some(c => (c.name || c)?.toLowerCase().includes(q)));
          if (!matchName && !matchDesc && !matchCat) return false;
        }

        if (timeFilter === 'fast' && product.printTimeHours >= 12) return false;
        if (timeFilter === 'standard' && (product.printTimeHours < 12 || product.printTimeHours > 20)) return false;
        if (timeFilter === 'complex' && product.printTimeHours <= 20) return false;

        return true;
      }).sort((a, b) => {
        if (sortBy === 'price-asc') return a.price - b.price;
        if (sortBy === 'price-desc') return b.price - a.price;
        if (sortBy === 'time-asc') return a.printTimeHours - b.printTimeHours;
        return b.reviewsCount - a.reviewsCount;
      });
    }

    return productList.filter((product) => {
      // 1. Verificação Multi-categoria
      let isMatch = false;

      if (Array.isArray(product.categories) && product.categories.length > 0) {
        isMatch = product.categories.some((cat) => {
          if (!cat) return false;
          const slug = typeof cat === 'object' ? cat.slug : String(cat);
          const id = typeof cat === 'object' ? cat.id : String(cat);
          const name = typeof cat === 'object' ? (cat.name || '') : String(cat);

          return (
            slug === selectedCategory ||
            id === selectedCategory ||
            name.toLowerCase() === selectedCategory.toLowerCase() ||
            (selectedCategory === 'decor' && (slug === 'decoracao' || slug === 'decor')) ||
            (selectedCategory === 'decoracao' && (slug === 'decoracao' || slug === 'decor')) ||
            (selectedCategory === 'geek' && (slug === 'suportes-geek' || slug === 'geek')) ||
            (selectedCategory === 'suportes-geek' && (slug === 'suportes-geek' || slug === 'geek')) ||
            (selectedCategory === 'articulated' && (slug === 'toys-articulados' || slug === 'articulados-toys' || slug === 'articulated')) ||
            (selectedCategory === 'articulados-toys' && (slug === 'toys-articulados' || slug === 'articulados-toys' || slug === 'articulated')) ||
            (selectedCategory === 'toys-articulados' && (slug === 'toys-articulados' || slug === 'articulados-toys' || slug === 'articulated'))
          );
        });
      }

      if (!isMatch) {
        isMatch = product.category === selectedCategory ||
          product.categoryName?.toLowerCase() === selectedCategory.toLowerCase() ||
          (selectedCategory === 'decor' && (product.category === 'decoracao' || product.category === 'decor')) ||
          (selectedCategory === 'decoracao' && (product.category === 'decoracao' || product.category === 'decor')) ||
          (selectedCategory === 'geek' && (product.category === 'suportes-geek' || product.category === 'geek')) ||
          (selectedCategory === 'suportes-geek' && (product.category === 'suportes-geek' || product.category === 'geek')) ||
          (selectedCategory === 'articulated' && (product.category === 'toys-articulados' || product.category === 'articulados-toys' || product.category === 'articulated')) ||
          (selectedCategory === 'articulados-toys' && (product.category === 'toys-articulados' || product.category === 'articulados-toys' || product.category === 'articulated'));
      }

      if (!isMatch) return false;

      // 2. Search query match
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchName = product.name?.toLowerCase().includes(q);
        const matchDesc = product.shortDescription?.toLowerCase().includes(q) || product.fullDescription?.toLowerCase().includes(q);
        const matchCat = product.categoryName?.toLowerCase().includes(q) ||
          (Array.isArray(product.categories) && product.categories.some(c => (c.name || c)?.toLowerCase().includes(q)));
        if (!matchName && !matchDesc && !matchCat) return false;
      }

      // 3. Print time filter
      if (timeFilter === 'fast' && product.printTimeHours >= 12) return false;
      if (timeFilter === 'standard' && (product.printTimeHours < 12 || product.printTimeHours > 20)) return false;
      if (timeFilter === 'complex' && product.printTimeHours <= 20) return false;

      return true;
    }).sort((a, b) => {
      if (sortBy === 'price-asc') return a.price - b.price;
      if (sortBy === 'price-desc') return b.price - a.price;
      if (sortBy === 'time-asc') return a.printTimeHours - b.printTimeHours;
      return b.reviewsCount - a.reviewsCount;
    });
  }, [productList, selectedCategory, searchQuery, timeFilter, sortBy]);

  const displayList = initialLimit ? filteredProducts.slice(0, initialLimit) : filteredProducts;

  const countProductsInCategory = (catId) => {
    if (catId === 'all') return productList.length;
    return productList.filter((p) => {
      if (Array.isArray(p.categories) && p.categories.length > 0) {
        return p.categories.some((c) => {
          const slug = typeof c === 'object' ? c.slug : String(c);
          const id = typeof c === 'object' ? c.id : String(c);
          return (
            slug === catId ||
            id === catId ||
            (catId === 'decor' && (slug === 'decoracao' || slug === 'decor')) ||
            (catId === 'decoracao' && (slug === 'decoracao' || slug === 'decor')) ||
            (catId === 'geek' && (slug === 'suportes-geek' || slug === 'geek')) ||
            (catId === 'suportes-geek' && (slug === 'suportes-geek' || slug === 'geek')) ||
            (catId === 'articulated' && (slug === 'toys-articulados' || slug === 'articulados-toys' || slug === 'articulated')) ||
            (catId === 'articulados-toys' && (slug === 'toys-articulados' || slug === 'articulados-toys' || slug === 'articulated'))
          );
        });
      }
      return p.category === catId;
    }).length;
  };

  return (
    <section id="catalog-section" className="py-12 sm:py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      
      {/* Section Header */}
      <div className="flex flex-col md:flex-row md:items-end justify-between mb-8 gap-4">
        <div>
          <div className="inline-flex items-center gap-1.5 bg-mimoo-purple-tint text-mimoo-purple font-fredoka text-xs font-bold px-3 py-1 rounded-full border border-purple-200 mb-2">
            <Sparkles className="w-3.5 h-3.5" />
            <span>Catálogo Exclusivo Feito Sob Encomenda</span>
          </div>
          <h2 className="font-fredoka font-bold text-3xl sm:text-4xl text-mimoo-dark tracking-tight">
            {title || 'Criações em 3D da MIMOO LAB'}
          </h2>
          <p className="font-nunito text-slate-500 text-sm sm:text-base mt-1">
            {subtitle || 'Cada peça é fabricada em filamento PLA premium assim que você faz seu pedido.'}
          </p>
        </div>

        {/* Total Results Count */}
        <div className="text-xs font-fredoka text-slate-500 bg-white px-3 py-1.5 rounded-xl border border-slate-200 self-start md:self-auto">
          Mostrando <strong>{displayList.length}</strong> de {productList.length} criações
        </div>
      </div>

      {/* Category Pills & Filters */}
      {showFilters && (
        <div className="space-y-4 mb-8">
          
          {/* Category Tabs Scroll */}
          <div className="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
            {CATEGORIES.map((cat) => {
              const isSelected = selectedCategory === cat.id;
              const meta = cat.id === 'all' ? { icon: '✨', name: 'Todos os Mimos' } : getCategoryMeta(cat.id || cat.name);
              return (
                <button
                  key={cat.id}
                  onClick={() => setSelectedCategory(cat.id)}
                  className={`flex items-center gap-2 px-4 py-2.5 rounded-2xl font-fredoka text-xs sm:text-sm font-semibold whitespace-nowrap transition-all ${
                    isSelected
                      ? 'bg-mimoo-purple text-white shadow-chunky-sm border-2 border-mimoo-dark -translate-y-0.5'
                      : 'bg-white text-slate-700 border-2 border-mimoo-dark/40 hover:border-mimoo-dark hover:bg-slate-50'
                  }`}
                >
                  <span className="select-none">{meta.icon}</span>
                  <span>{cat.name}</span>
                  <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-bold ${
                    isSelected ? 'bg-white/30 text-white' : 'bg-slate-100 text-slate-500'
                  }`}>
                    {countProductsInCategory(cat.id)}
                  </span>
                </button>
              );
            })}
          </div>

          {/* Sub Filters Row: Time Filter + Sorting */}
          <div className="bg-slate-50 p-3 sm:p-4 rounded-2xl border-2 border-mimoo-dark/30 flex flex-wrap items-center justify-between gap-3 text-xs font-fredoka">
            
            {/* Filter by Print Time */}
            <div className="flex items-center gap-2 flex-wrap">
              <span className="flex items-center gap-1 text-slate-500 font-bold">
                <Clock className="w-3.5 h-3.5 text-mimoo-purple" />
                Tempo de Impressão:
              </span>

              <button
                onClick={() => setTimeFilter('all')}
                className={`px-2.5 py-1 rounded-xl transition-colors ${
                  timeFilter === 'all' ? 'bg-mimoo-dark text-white font-bold' : 'bg-white text-slate-600 hover:bg-slate-200'
                }`}
              >
                Todos
              </button>

              <button
                onClick={() => setTimeFilter('fast')}
                className={`px-2.5 py-1 rounded-xl transition-colors ${
                  timeFilter === 'fast' ? 'bg-mimoo-mint text-mimoo-dark font-bold' : 'bg-white text-slate-600 hover:bg-slate-200'
                }`}
              >
                ⚡ Rápido (&lt; 12h)
              </button>

              <button
                onClick={() => setTimeFilter('standard')}
                className={`px-2.5 py-1 rounded-xl transition-colors ${
                  timeFilter === 'standard' ? 'bg-mimoo-yellow text-mimoo-dark font-bold' : 'bg-white text-slate-600 hover:bg-slate-200'
                }`}
              >
                ⏱️ Padrão (1 a 2 dias)
              </button>

              <button
                onClick={() => setTimeFilter('complex')}
                className={`px-2.5 py-1 rounded-xl transition-colors ${
                  timeFilter === 'complex' ? 'bg-mimoo-pink text-white font-bold' : 'bg-white text-slate-600 hover:bg-slate-200'
                }`}
              >
                🐉 Grandes & Articulados (+24h)
              </button>
            </div>

            {/* Sort Dropdown */}
            <div className="flex items-center gap-2">
              <span className="text-slate-500 font-bold flex items-center gap-1">
                <ArrowUpDown className="w-3.5 h-3.5" />
                Ordenar por:
              </span>
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value)}
                className="bg-white border-2 border-mimoo-dark rounded-xl px-2.5 py-1 font-fredoka text-xs focus:outline-none cursor-pointer"
              >
                <option value="popular">⭐ Mais Populares</option>
                <option value="time-asc">⏱️ Produção Mais Rápida</option>
                <option value="price-asc">💵 Menor Preço</option>
                <option value="price-desc">💎 Maior Preço</option>
              </select>
            </div>

          </div>

        </div>
      )}

      {/* Active Search Filter Banner */}
      {searchQuery && (
        <div className="mb-6 flex items-center justify-between bg-mimoo-purple-tint p-3 rounded-2xl border border-mimoo-purple/30 text-xs font-fredoka">
          <div className="flex items-center gap-2 text-mimoo-purple font-semibold">
            <span>Resultados para a busca: "<strong>{searchQuery}</strong>"</span>
          </div>
          <button
            onClick={() => setSearchQuery('')}
            className="flex items-center gap-1 text-slate-600 hover:text-mimoo-dark font-bold underline"
          >
            <RotateCcw className="w-3 h-3" />
            Limpar Busca
          </button>
        </div>
      )}

      {/* Products Grid */}
      {displayList.length > 0 ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
          {displayList.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
      ) : (
        /* Empty State */
        <div className="card-chunky p-12 text-center max-w-md mx-auto space-y-4 my-8">
          <div className="w-16 h-16 rounded-full bg-mimoo-pink-light border-2 border-mimoo-dark flex items-center justify-center mx-auto text-3xl">
            🐮
          </div>
          <h3 className="font-fredoka font-bold text-xl text-mimoo-dark">
            Nenhuma criação encontrada!
          </h3>
          <p className="font-nunito text-xs sm:text-sm text-slate-500">
            Não encontramos itens com os filtros atuais. Tente buscar por outros termos ou redefinir suas opções.
          </p>
          <button
            onClick={() => {
              setSelectedCategory('all');
              setTimeFilter('all');
              setSearchQuery('');
            }}
            className="btn-chunky-purple px-5 py-2.5 text-xs font-bold"
          >
            Restaurar Catálogo Completo
          </button>
        </div>
      )}

    </section>
  );
};

export default ProductGrid;
