import React from 'react';
import { useShop } from '../context/ShopContext';
import { PrintTimeBadge } from './PrintTimeBadge';
import { CategoryBadge } from './ui/CategoryBadge';
import { Heart, Star, ShoppingBag, Eye } from 'lucide-react';
import { FILAMENT_COLORS } from '../data/mockProducts';
import { calculateProductPricing } from '../utils/pricing';

export const ProductCard = ({ product }) => {
  const { openProductDetail, toggleFavorite, favorites, addToCart } = useShop();
  const isFav = favorites.includes(product.id);

  const pricing = calculateProductPricing({
    basePriceCents: product.base_price_cents ?? (product.price * 100),
    quantity: 1,
    pixDiscountPercent: 10,
    maxInstallments: 3,
  });

  const displayColors = (product.colors && product.colors.length > 0) ? product.colors : FILAMENT_COLORS;

  // Compra rápida: primeiro material do produto que tenha cor ligada desse filamento (o checkout recusa as demais)
  const findQuickAddChoice = () => {
    const materials = (product.materials && product.materials.length > 0) ? product.materials : [{ id: 'pla', name: 'PLA' }];
    for (const material of materials) {
      const color = (product.colors || []).find(
        (c) => c.material_type?.toLowerCase() === String(material.id).toLowerCase()
      );
      if (color) return { material, color };
    }
    return null;
  };

  const handleQuickAdd = (e) => {
    e.stopPropagation();
    const choice = findQuickAddChoice();
    // Sem cor compatível: abre os detalhes, que mostram as opções (ou "Sem cores disponíveis")
    if (!choice) {
      openProductDetail(product);
      return;
    }
    addToCart(
      product, 
      choice.color, 
      product.sizeOptions?.[0] || { id: 'p', label: 'Padrão (12cm)', multiplier: 1, addPrice: 0, addTime: 0 }, 
      1,
      choice.material
    );
  };

  return (
    <div 
      onClick={() => openProductDetail(product)}
      className="group relative bg-white rounded-3xl border-2 border-mimoo-dark shadow-chunky hover:shadow-chunky-lg hover:-translate-y-1.5 transition-all duration-200 cursor-pointer flex flex-col overflow-hidden"
    >
      {/* Top Image Container */}
      <div className="relative aspect-square w-full bg-slate-100 overflow-hidden border-b-2 border-mimoo-dark">
        <img 
          src={product.image} 
          alt={product.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
          loading="lazy"
        />

        {/* Top Badges */}
        <div className="absolute top-3 left-3 flex flex-col gap-1.5 items-start">
          {product.badge && (
            <span className={`badge-chunky ${product.badgeColor || 'bg-mimoo-pink text-white'}`}>
              {product.badge}
            </span>
          )}
          <PrintTimeBadge hours={product.printTimeHours} variant="compact" />
        </div>

        {/* Favorite Button */}
        <button
          onClick={(e) => {
            e.stopPropagation();
            toggleFavorite(product.id);
          }}
          className="absolute top-3 right-3 p-2 rounded-2xl border-2 border-mimoo-dark bg-white/90 backdrop-blur-sm shadow-chunky-sm hover:scale-110 active:scale-95 transition-all"
          title={isFav ? 'Remover dos favoritos' : 'Adicionar aos favoritos'}
        >
          <Heart className={`w-4 h-4 ${isFav ? 'text-mimoo-pink fill-mimoo-pink' : 'text-slate-600'}`} />
        </button>

        {/* Hover overlay hint */}
        <div className="absolute inset-0 bg-mimoo-purple/20 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
          <span className="bg-white text-mimoo-dark font-fredoka text-xs font-bold px-3 py-1.5 rounded-full border-2 border-mimoo-dark shadow-chunky-sm flex items-center gap-1.5 translate-y-2 group-hover:translate-y-0 transition-transform">
            <Eye className="w-4 h-4 text-mimoo-purple" />
            Ver Opções & Cores
          </span>
        </div>
      </div>

      {/* Card Content */}
      <div className="p-4 sm:p-5 flex-1 flex flex-col justify-between space-y-3">
        
        <div>
          {/* Category & Rating */}
          <div className="flex items-center justify-between text-xs mb-1.5 gap-2">
            <div className="flex flex-wrap gap-1">
              {Array.isArray(product.categories) && product.categories.length > 0 ? (
                product.categories.map((category) => (
                  <CategoryBadge
                    key={category.id || category.slug || category.name}
                    category={category}
                    size="sm"
                  />
                ))
              ) : (
                <CategoryBadge
                  category={product.categoryName || product.category || 'Decoração'}
                  size="sm"
                />
              )}
            </div>
            <div className="flex items-center gap-1 text-amber-500 font-bold font-fredoka flex-shrink-0">
              <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
              <span>{product.rating}</span>
              <span className="text-slate-400 font-normal">({product.reviewsCount})</span>
            </div>
          </div>

          {/* Product Title */}
          <h3 className="font-fredoka font-bold text-base sm:text-lg text-mimoo-dark group-hover:text-mimoo-purple transition-colors line-clamp-1">
            {product.name}
          </h3>

          <p className="text-xs text-slate-500 font-nunito line-clamp-2 mt-1">
            {product.shortDescription}
          </p>
        </div>

        {/* Filament Color Swatches Preview */}
        <div className="pt-2 border-t border-slate-100">
          <div className="flex items-center justify-between text-[11px] text-slate-500 font-fredoka mb-1.5">
            <span>Cores Disponíveis:</span>
            <span className="text-mimoo-purple font-bold">{displayColors.length} opções</span>
          </div>
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none">
            {displayColors.slice(0, 6).map((c) => (
              <span
                key={c.id}
                title={c.name}
                className="w-4 h-4 rounded-full border border-mimoo-dark/50 flex-shrink-0 transition-transform hover:scale-125 shadow-sm"
                style={{ background: c.hex }}
              />
            ))}
            {displayColors.length > 6 && (
              <span className="text-[10px] font-fredoka font-bold text-slate-400 ml-0.5">
                +{displayColors.length - 6}
              </span>
            )}
          </div>
        </div>

        {/* Price and CTA */}
        <div className="pt-2 border-t border-slate-100 flex items-end justify-between gap-2">
          <div className="flex flex-col min-w-0">
            {/* Linha Principal: Preço PIX com Destaque Máximo */}
            <div className="flex items-baseline gap-1.5 flex-wrap">
              <span className="font-fredoka font-black text-xl sm:text-2xl text-mimoo-dark tracking-tight">
                {pricing.totalPixFormatted}
              </span>
              <span className="px-2 py-0.5 text-[10px] font-extrabold bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-lg shadow-sm">
                no PIX (-10%)
              </span>
            </div>

            {/* Linha Secundária: Preço a Prazo no Cartão */}
            <div className="text-[11px] text-slate-500 font-medium mt-0.5 font-nunito truncate">
              ou <span className="font-semibold text-slate-700">{pricing.totalCartaoFormatted}</span> em até {pricing.maxInstallments}x de {pricing.installmentValueFormatted}
            </div>
          </div>

          {/* Quick Customize Button */}
          <button
            onClick={handleQuickAdd}
            className="p-2.5 sm:px-3 sm:py-2 rounded-2xl border-2 border-mimoo-dark bg-mimoo-purple text-white shadow-chunky-sm hover:bg-mimoo-purple-dark active:translate-x-0.5 active:translate-y-0.5 active:shadow-none transition-all flex items-center gap-1 font-fredoka text-xs font-bold flex-shrink-0"
            title="Personalizar e encomendar"
          >
            <ShoppingBag className="w-4 h-4" />
            <span className="hidden sm:inline">Pedir</span>
          </button>
        </div>

      </div>
    </div>
  );
};

export default ProductCard;
