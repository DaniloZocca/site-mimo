import React, { useState, useEffect, useMemo } from 'react';
import { useShop } from '../context/ShopContext';
import { calculateProductPricing } from '../utils/pricing';
import { sanitizeHtml } from '../utils/sanitize';
import { CategoryBadge } from './ui/CategoryBadge';
import { 
  X, 
  Heart, 
  Star, 
  ShoppingBag, 
  Truck, 
  Clock, 
  Check,
  Layers,
  Sparkles
} from 'lucide-react';

const DEFAULT_MATERIALS = [
  {
    id: 'pla',
    name: 'PLA',
    full_name: 'PLA Premium (Atóxico e Biodegradável)',
    description: 'Polímero de origem vegetal (amido de milho). Ideal para decoração e colecionáveis.',
  },
  {
    id: 'petg',
    name: 'PETG',
    full_name: 'PETG Resistente (Uso Externo & Térmico)',
    description: 'Alta resistência a impactos e raios UV. Ideal para peças funcionais e suportes.',
  },
  {
    id: 'abs',
    name: 'ABS',
    full_name: 'ABS Industrial (Alta Resistência Mecânica)',
    description: 'Resiste a temperaturas de até 90°C. Acabamento rígido e excelente durabilidade.',
  },
  {
    id: 'tpu',
    name: 'TPU Flexível',
    full_name: 'TPU Flexível (Emborrachado & Maleável)',
    description: 'Filamento elastômero flexível. Resistente a torções e impacto.',
  },
];

export const ProductDetailModal = () => {
  const { selectedProduct } = useShop();

  if (!selectedProduct) return null;

  // A key remonta o conteúdo a cada produto aberto: o estado (material, cor, tamanho...) começa do zero
  return <ProductDetailContent key={selectedProduct.id} selectedProduct={selectedProduct} />;
};

const ProductDetailContent = ({ selectedProduct }) => {
  const { 
    closeProductDetail, 
    addToCart, 
    favorites, 
    toggleFavorite 
  } = useShop();

  // Materials available for this product
  const availableMaterials = useMemo(() => {
    return (selectedProduct.materials && selectedProduct.materials.length > 0)
      ? selectedProduct.materials
      : DEFAULT_MATERIALS;
  }, [selectedProduct.materials]);

  // 1. Selected Material State
  const [selectedMaterial, setSelectedMaterial] = useState(availableMaterials[0]);

  // Reset selected material if product changes
  useEffect(() => {
    if (availableMaterials.length > 0) {
      setSelectedMaterial(availableMaterials[0]);
    }
  }, [availableMaterials]);

  // 2. Cores ligadas ao produto cujo filamento é do material ativo (o checkout recusa as demais)
  const availableColorsForMaterial = useMemo(() => {
    const productColors = selectedProduct?.colors || [];

    if (!selectedMaterial) return [];

    return productColors.filter(
      (c) => c.material_type?.toLowerCase() === selectedMaterial.id.toLowerCase()
    );
  }, [selectedProduct?.colors, selectedMaterial]);

  // Selected filament color state
  const [selectedColor, setSelectedColor] = useState(availableColorsForMaterial[0] || null);

  // Ao trocar o material, limpa a cor se ela não existir no material novo
  useEffect(() => {
    if (selectedColor && !availableColorsForMaterial.some((c) => c.id === selectedColor.id)) {
      setSelectedColor(null);
    }
  }, [availableColorsForMaterial, selectedColor]);

  const noColorsForMaterial = availableColorsForMaterial.length === 0;

  // 3. Selected Size State
  const [selectedSize, setSelectedSize] = useState(
    selectedProduct.sizeOptions && selectedProduct.sizeOptions.length > 0 
      ? selectedProduct.sizeOptions[0] 
      : { id: 'p', label: 'Padrão (12cm)', multiplier: 1, addTime: 0, addPrice: 0, dimensions: '12 x 10 x 8 cm' }
  );

  // Quantity
  const [quantity, setQuantity] = useState(1);

  // Active gallery index
  const [activeImage, setActiveImage] = useState(selectedProduct.image);

  // CEP Simulator state
  const [cepInput, setCepInput] = useState('');
  const [cepResult, setCepResult] = useState(null);
  const [isCalculatingCep, setIsCalculatingCep] = useState(false);

  // Active Tab for details - Default: 'about'
  const [activeTab, setActiveTab] = useState('about'); // 'about' | 'specs' | 'care'

  const isFav = favorites.includes(selectedProduct.id);

  // Dynamic pricing and print time calculation based on size & quantity
  const sizeModifierCents = selectedSize?.price_modifier_cents ?? ((selectedSize?.addPrice || 0) * 100);
  const basePriceCents = selectedProduct.base_price_cents ?? (selectedProduct.price * 100);

  const pricing = calculateProductPricing({
    basePriceCents,
    sizeModifierCents,
    quantity,
    pixDiscountPercent: 10,
    maxInstallments: 3,
  });

  const finalPrintHours = selectedProduct.printTimeHours + (selectedSize?.addTime || 0);

  const handleAddToCart = () => {
    addToCart(selectedProduct, selectedColor, selectedSize, quantity, selectedMaterial);
    closeProductDetail();
  };

  const handleSimulateCep = (e) => {
    e.preventDefault();
    if (!cepInput.trim()) return;

    setIsCalculatingCep(true);
    setTimeout(() => {
      setIsCalculatingCep(false);
      setCepResult({
        productionTime: `${Math.ceil(finalPrintHours / 12)} a ${Math.ceil(finalPrintHours / 8)} dias úteis`,
        carrierTime: '2 a 3 dias úteis',
        totalEstimate: `${Math.ceil(finalPrintHours / 12) + 2} a ${Math.ceil(finalPrintHours / 8) + 3} dias úteis`,
        price: 'R$ 14,90',
        carrier: 'Jadlog Expresso / Correios Sedex',
      });
    }, 600);
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-mimoo-dark/60 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4 md:p-6 animate-in fade-in duration-200">
      
      {/* Modal Container */}
      <div className="relative w-full max-w-4xl bg-white rounded-4xl border-3 border-mimoo-dark shadow-chunky-lg overflow-hidden my-auto max-h-[92vh] flex flex-col">
        
        {/* Header Close Button */}
        <div className="absolute top-4 right-4 z-20 flex items-center gap-2">
          <button
            onClick={() => toggleFavorite(selectedProduct.id)}
            className="p-2.5 rounded-full border-2 border-mimoo-dark bg-white shadow-chunky-sm hover:scale-105 transition-all"
            title={isFav ? 'Remover dos favoritos' : 'Favoritar'}
          >
            <Heart className={`w-4 h-4 ${isFav ? 'text-mimoo-pink fill-mimoo-pink' : 'text-slate-600'}`} />
          </button>
          
          <button
            onClick={closeProductDetail}
            className="p-2.5 rounded-full border-2 border-mimoo-dark bg-white shadow-chunky-sm hover:bg-slate-100 hover:rotate-90 transition-all duration-200"
            title="Fechar Detalhes"
          >
            <X className="w-5 h-5 text-mimoo-dark" />
          </button>
        </div>

        {/* Scrollable Content Body */}
        <div className="overflow-y-auto p-4 sm:p-6 md:p-8 space-y-8">
          
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8">
            
            {/* Left Column: Image & Gallery */}
            <div className="lg:col-span-6 space-y-4">
              
              {/* Main Featured Photo */}
              <div className="relative aspect-square w-full rounded-3xl overflow-hidden border-2 border-mimoo-dark bg-slate-50 shadow-chunky-sm">
                <img 
                  src={activeImage} 
                  alt={selectedProduct.name}
                  className="w-full h-full object-cover"
                />
                
                {/* Top Badge */}
                {selectedProduct.badge && (
                  <span className={`absolute top-3 left-3 badge-chunky ${selectedProduct.badgeColor || 'bg-mimoo-pink text-white'}`}>
                    {selectedProduct.badge}
                  </span>
                )}

                {/* Bottom Print Time Pill */}
                <div className="absolute bottom-3 right-3 bg-white/95 backdrop-blur-sm border-2 border-mimoo-dark rounded-2xl px-3 py-1.5 text-xs font-fredoka font-bold text-mimoo-dark shadow-chunky-sm flex items-center gap-1.5">
                  <Clock className="w-4 h-4 text-mimoo-purple" />
                  <span>~{finalPrintHours}h de Impressão</span>
                </div>
              </div>

              {/* Gallery Thumbnails */}
              {selectedProduct.gallery && selectedProduct.gallery.length > 1 && (
                <div className="flex items-center gap-3 overflow-x-auto pb-1">
                  {selectedProduct.gallery.map((img, idx) => (
                    <button
                      key={idx}
                      onClick={() => setActiveImage(img)}
                      className={`w-20 h-20 rounded-2xl border-2 overflow-hidden flex-shrink-0 transition-all ${
                        activeImage === img
                          ? 'border-mimoo-purple shadow-chunky-sm scale-105'
                          : 'border-mimoo-dark/40 opacity-70 hover:opacity-100'
                      }`}
                    >
                      <img src={img} alt="" className="w-full h-full object-cover" />
                    </button>
                  ))}
                </div>
              )}

              {/* Guarantee / Eco Box */}
              <div className="grid grid-cols-2 gap-3 pt-2">
                <div className="bg-mimoo-purple-tint p-3 rounded-2xl border border-purple-200 flex items-center gap-2.5">
                  <span className="text-xl">🌱</span>
                  <div className="text-xs font-fredoka">
                    <strong className="block text-mimoo-dark">{selectedMaterial?.name || 'PLA'} Sustentável</strong>
                    <span className="text-slate-500 font-normal">Manufatura sob demanda</span>
                  </div>
                </div>

                <div className="bg-mimoo-mint-light p-3 rounded-2xl border border-emerald-300 flex items-center gap-2.5">
                  <span className="text-xl">🛡️</span>
                  <div className="text-xs font-fredoka">
                    <strong className="block text-emerald-900">Garantia MIMOO</strong>
                    <span className="text-emerald-700 font-normal">Qualidade ou reimpressão</span>
                  </div>
                </div>
              </div>

            </div>

            {/* Right Column: Title, Material Selector, Color, Size, and CTA */}
            <div className="lg:col-span-6 flex flex-col justify-between space-y-5">
              
              <div className="space-y-3.5">
                {/* Category & Rating */}
                <div className="flex items-center justify-between text-xs gap-2">
                  <div className="flex flex-wrap items-center gap-2">
                    {Array.isArray(selectedProduct.categories) && selectedProduct.categories.length > 0 ? (
                      selectedProduct.categories.map((cat) => (
                        <CategoryBadge
                          key={cat.id || cat.slug || cat.name}
                          category={cat}
                          size="md"
                        />
                      ))
                    ) : (
                      <CategoryBadge
                        category={selectedProduct.categoryName || selectedProduct.category || 'Decoração'}
                        size="md"
                      />
                    )}
                  </div>
                  
                  <div className="flex items-center gap-1 font-fredoka font-bold text-amber-500 flex-shrink-0">
                    <Star className="w-4 h-4 fill-amber-400 text-amber-400" />
                    <span>{selectedProduct.rating || 5.0}</span>
                    <span className="text-slate-400 font-normal">({selectedProduct.reviewsCount || 1} avaliações)</span>
                  </div>
                </div>

                {/* Product Title & Summary */}
                <div>
                  <h1 className="font-fredoka font-bold text-2xl sm:text-3xl text-mimoo-dark leading-tight">
                    {selectedProduct.name}
                  </h1>
                  
                  <div className="flex flex-col gap-1 mt-1.5">
                    <span className="text-xs font-semibold text-mimoo-purple uppercase tracking-wider font-fredoka flex items-center gap-1">
                      <Sparkles className="w-3.5 h-3.5" />
                      Peça Artesanal • Manufatura Aditiva
                    </span>
                    {(selectedProduct.description_excerpt || selectedProduct.shortDescription || selectedProduct.tagline) && (
                      <p className="text-xs sm:text-sm text-slate-500 line-clamp-2 leading-relaxed font-nunito">
                        {selectedProduct.description_excerpt || selectedProduct.shortDescription || selectedProduct.tagline}
                      </p>
                    )}
                  </div>
                </div>

                {/* Price Display Container */}
                <div className="rounded-2xl bg-purple-50/70 p-4 border border-purple-200/80 shadow-sm flex flex-col gap-1.5">
                  {/* Preço PIX - Destaque Absoluto */}
                  <div className="flex items-center gap-3 flex-wrap">
                    <span className="font-fredoka text-3xl sm:text-4xl font-black text-purple-950 tracking-tight">
                      {pricing.totalPixFormatted}
                    </span>
                    <span className="px-2.5 py-1 text-xs font-black bg-emerald-500 text-white rounded-xl shadow-sm uppercase tracking-wide">
                      10% OFF no PIX
                    </span>
                  </div>

                  {/* Preço Parcelado no Cartão */}
                  <span className="text-xs sm:text-sm font-semibold text-slate-600 font-nunito">
                    ou <span className="text-slate-900 font-bold">{pricing.totalCartaoFormatted}</span> em até {pricing.maxInstallments}x de <span className="text-slate-900 font-bold">{pricing.installmentValueFormatted}</span> sem juros
                  </span>

                  <span className="text-xs text-emerald-600 font-bold font-fredoka flex items-center gap-1">
                    ✨ Economize {pricing.savingsFormatted} pagando via PIX instantâneo!
                  </span>
                </div>

                {/* 1. Escolha do Material Técnico */}
                {availableMaterials && availableMaterials.length > 0 && (
                  <div className="space-y-2 pt-1">
                    <div className="flex items-center justify-between text-xs font-fredoka">
                      <label className="font-bold text-mimoo-dark flex items-center gap-1.5">
                        <Layers className="w-3.5 h-3.5 text-mimoo-purple" />
                        <span>1. Escolha o Material:</span>
                        <strong className="text-mimoo-purple font-bold">({selectedMaterial?.name || 'PLA'})</strong>
                      </label>
                      <span className="text-slate-400 font-nunito text-[11px]">Polímero de Extrusão</span>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                      {availableMaterials.map((mat) => {
                        const isSelected = selectedMaterial?.id === mat.id;
                        return (
                          <button
                            key={mat.id}
                            type="button"
                            onClick={() => setSelectedMaterial(mat)}
                            className={`p-2.5 rounded-2xl border-2 text-left transition-all flex flex-col justify-between ${
                              isSelected
                                ? 'border-mimoo-purple bg-mimoo-purple-tint shadow-chunky-sm ring-2 ring-mimoo-purple/30 -translate-y-0.5'
                                : 'border-slate-200 hover:border-slate-400 bg-white'
                            }`}
                          >
                            <div className="flex items-center justify-between">
                              <span className={`font-fredoka font-bold text-xs ${isSelected ? 'text-mimoo-purple' : 'text-slate-800'}`}>
                                {mat.name}
                              </span>
                              {isSelected && <Check className="w-3.5 h-3.5 text-mimoo-purple flex-shrink-0" />}
                            </div>
                            <span className="text-[10px] text-slate-500 font-nunito line-clamp-1 mt-1">
                              {mat.description}
                            </span>
                          </button>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* 2. Escolha da Cor do Filamento (Filtrada pelo Material) */}
                <div className="space-y-2 pt-1">
                  <div className="flex items-center justify-between text-xs font-fredoka">
                    <span className="font-bold text-mimoo-dark flex items-center gap-1.5">
                      <span>2. Escolha a Cor do Filamento:</span>
                      <strong className="text-mimoo-purple font-bold">({selectedColor?.name || 'escolha uma cor'})</strong>
                    </span>
                    <span className="text-slate-400 font-nunito text-[11px]">
                      {availableColorsForMaterial.length} {availableColorsForMaterial.length === 1 ? 'opção' : 'opções'} em {selectedMaterial?.name || 'PLA'}
                    </span>
                  </div>

                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {availableColorsForMaterial.map((c) => {
                      const isPicked = selectedColor?.id === c.id;
                      return (
                        <button
                          key={c.id}
                          type="button"
                          onClick={() => setSelectedColor(c)}
                          className={`p-2 rounded-2xl border-2 flex items-center gap-2 text-left transition-all ${
                            isPicked
                              ? 'border-mimoo-purple bg-mimoo-purple-tint shadow-chunky-sm ring-2 ring-mimoo-purple/30 -translate-y-0.5'
                              : 'border-slate-200 hover:border-slate-400 bg-white'
                          }`}
                        >
                          <span 
                            className="w-5 h-5 rounded-full border border-mimoo-dark/30 flex-shrink-0 shadow-sm flex items-center justify-center"
                            style={{ background: c.hex }}
                          >
                            {isPicked && <Check className="w-3 h-3 text-white drop-shadow" />}
                          </span>
                          <div className="min-w-0">
                            <span className="block font-fredoka font-semibold text-[11px] truncate text-slate-800">
                              {c.name}
                            </span>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                </div>

                {/* 3. Escolha o Tamanho / Escala */}
                {selectedProduct.sizeOptions && selectedProduct.sizeOptions.length > 1 && (
                  <div className="space-y-2 pt-1">
                    <div className="flex items-center justify-between text-xs font-fredoka">
                      <span className="font-bold text-mimoo-dark">3. Escolha o Tamanho / Escala:</span>
                      <span className="text-slate-400 font-nunito text-[11px]">{selectedSize?.dimensions}</span>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                      {selectedProduct.sizeOptions.map((s) => {
                        const isPicked = selectedSize?.id === s.id;
                        return (
                          <button
                            key={s.id}
                            type="button"
                            onClick={() => setSelectedSize(s)}
                            className={`p-2.5 rounded-2xl border-2 text-left transition-all ${
                              isPicked
                                ? 'border-mimoo-purple bg-mimoo-purple-tint shadow-chunky-sm font-bold text-mimoo-purple'
                                : 'border-slate-200 hover:border-slate-400 bg-white text-slate-700'
                            }`}
                          >
                            <span className="font-fredoka text-xs block font-bold">{s.label}</span>
                            <div className="flex items-center justify-between text-[10px] text-slate-500 font-nunito mt-0.5">
                              <span>{s.addPrice > 0 ? `+ R$ ${s.addPrice.toFixed(2)}` : 'Incluso'}</span>
                              <span className="text-mimoo-purple font-semibold">
                                {s.addTime > 0 ? `+${s.addTime}h ⏱️` : 'Padrão'}
                              </span>
                            </div>
                          </button>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Quantity and CTA Buttons */}
                <div className="pt-2 flex items-center gap-3">
                  {/* Quantity Pill */}
                  <div className="flex items-center rounded-2xl border-2 border-mimoo-dark bg-white shadow-chunky-sm p-1">
                    <button
                      onClick={() => setQuantity(Math.max(1, quantity - 1))}
                      className="w-8 h-8 rounded-xl font-fredoka font-bold text-slate-700 hover:bg-slate-100 flex items-center justify-center text-base"
                    >
                      -
                    </button>
                    <span className="w-8 text-center font-fredoka font-bold text-sm text-mimoo-dark">
                      {quantity}
                    </span>
                    <button
                      onClick={() => setQuantity(quantity + 1)}
                      className="w-8 h-8 rounded-xl font-fredoka font-bold text-slate-700 hover:bg-slate-100 flex items-center justify-center text-base"
                    >
                      +
                    </button>
                  </div>

                  {/* Chunky Add to Cart Button */}
                  <button
                    onClick={handleAddToCart}
                    disabled={!selectedColor}
                    className="flex-1 btn-chunky-purple py-3.5 px-6 text-sm sm:text-base flex items-center justify-center gap-2 shadow-chunky font-fredoka font-bold disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ShoppingBag className="w-5 h-5" />
                    <span>Adicionar à Sacola • {pricing.totalCartaoFormatted}</span>
                  </button>
                </div>

                {!selectedColor && (
                  <p role="status" className="text-xs font-nunito font-semibold text-mimoo-pink">
                    {noColorsForMaterial ? 'Sem cores disponíveis neste material' : 'Escolha uma cor para adicionar à sacola'}
                  </p>
                )}

                {/* Shipping Simulator */}
                <div className="pt-3 border-t border-slate-200">
                  <form onSubmit={handleSimulateCep} className="space-y-2">
                    <div className="flex items-center justify-between text-xs font-fredoka">
                      <span className="text-slate-600 font-bold flex items-center gap-1">
                        <Truck className="w-3.5 h-3.5 text-mimoo-purple" />
                        Calcular Prazo de Produção + Frete (CEP):
                      </span>
                    </div>

                    <div className="flex gap-2">
                      <input
                        type="text"
                        value={cepInput}
                        onChange={(e) => setCepInput(e.target.value.replace(/\D/g, '').slice(0, 8))}
                        placeholder="Ex: 01310-100"
                        className="flex-1 rounded-xl border-2 border-mimoo-dark bg-slate-50 px-3 py-2 text-xs font-nunito focus:outline-none"
                      />
                      <button
                        type="submit"
                        disabled={isCalculatingCep || cepInput.length < 8}
                        className="btn-chunky-white px-4 py-2 text-xs font-bold disabled:opacity-50"
                      >
                        {isCalculatingCep ? 'Calculando...' : 'Calcular'}
                      </button>
                    </div>

                    {cepResult && (
                      <div className="bg-mimoo-purple-tint p-3 rounded-2xl border border-purple-200 text-xs font-nunito space-y-1.5 animate-in fade-in">
                        <div className="flex justify-between font-fredoka text-mimoo-dark font-bold">
                          <span>Tempo Total Estimado:</span>
                          <span className="text-mimoo-purple font-bold">{cepResult.totalEstimate}</span>
                        </div>
                        <div className="text-[11px] text-slate-600 flex justify-between">
                          <span>• Fabricação & Extrusão: {cepResult.productionTime}</span>
                          <span>• Envio: {cepResult.carrierTime} ({cepResult.price})</span>
                        </div>
                      </div>
                    )}
                  </form>
                </div>

              </div>

            </div>

          </div>

          {/* Bottom Tabs: About (Description) / Specs / Care */}
          <div className="pt-4 border-t border-slate-200">
            <div className="flex items-center gap-2 border-b border-slate-200 pb-2 mb-3 text-xs font-fredoka overflow-x-auto scrollbar-none">
              <button
                onClick={() => setActiveTab('about')}
                className={`px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap ${
                  activeTab === 'about' 
                    ? 'bg-mimoo-dark text-white font-bold shadow-sm' 
                    : 'text-slate-600 hover:text-mimoo-dark hover:bg-slate-100'
                }`}
              >
                📖 Sobre a Peça
              </button>
              <button
                onClick={() => setActiveTab('specs')}
                className={`px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap ${
                  activeTab === 'specs' 
                    ? 'bg-mimoo-dark text-white font-bold shadow-sm' 
                    : 'text-slate-600 hover:text-mimoo-dark hover:bg-slate-100'
                }`}
              >
                ⚙️ Ficha Técnica 3D
              </button>
              <button
                onClick={() => setActiveTab('care')}
                className={`px-3.5 py-1.5 rounded-xl transition-all whitespace-nowrap ${
                  activeTab === 'care' 
                    ? 'bg-mimoo-dark text-white font-bold shadow-sm' 
                    : 'text-slate-600 hover:text-mimoo-dark hover:bg-slate-100'
                }`}
              >
                🌸 Cuidados com a Peça
              </button>
            </div>

            {/* Tab 1: About (Description with XSS Prevention) */}
            {activeTab === 'about' && (
              <div className="bg-white rounded-2xl p-4 sm:p-5 border border-purple-100 shadow-sm animate-in fade-in">
                {(selectedProduct.description || selectedProduct.fullDescription) ? (
                  <div 
                    className="prose prose-sm prose-purple max-w-none text-slate-700 font-nunito leading-relaxed space-y-3 font-normal"
                    dangerouslySetInnerHTML={{ 
                      __html: sanitizeHtml(selectedProduct.description || selectedProduct.fullDescription) 
                    }}
                  />
                ) : (
                  <p className="text-xs sm:text-sm text-slate-400 font-nunito italic">
                    Nenhuma descrição detalhada informada para esta peça.
                  </p>
                )}
              </div>
            )}

            {/* Tab 2: Dynamic 3D Technical Specs */}
            {activeTab === 'specs' && (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-xs font-nunito text-slate-600 animate-in fade-in">
                
                {/* Destaque do Polímero Selecionado */}
                <div className="bg-purple-50/70 p-3.5 rounded-2xl border border-purple-200/80 col-span-2 sm:col-span-3 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="text-purple-900 font-fredoka font-bold text-xs flex items-center gap-1.5">
                      <Layers className="w-4 h-4 text-mimoo-purple" />
                      Polímero Escolhido para Manufatura:
                    </span>
                    <span className="px-2.5 py-0.5 rounded-full bg-mimoo-purple text-white font-fredoka font-bold text-[10px] shadow-sm">
                      {selectedMaterial?.name || 'PLA'}
                    </span>
                  </div>
                  <strong className="text-mimoo-dark block text-sm font-fredoka">
                    {selectedMaterial?.full_name || selectedMaterial?.name || 'PLA Premium (Atóxico e Biodegradável)'}
                  </strong>
                  <p className="text-xs text-slate-600 font-nunito leading-relaxed">
                    {selectedMaterial?.description || 'Polímero ecológico derivado de amido vegetal, perfeito para acabamentos ricos em detalhes e cores vibrantes.'}
                  </p>
                </div>

                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                  <span className="text-slate-400 block text-[10px] font-fredoka">Tecnologia:</span>
                  <strong className="text-slate-800">{selectedProduct.specs?.technology || 'FDM Alta Precisão'}</strong>
                </div>

                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                  <span className="text-slate-400 block text-[10px] font-fredoka">Peso da Peça:</span>
                  <strong className="text-slate-800">{selectedProduct.weight_grams ? `${selectedProduct.weight_grams}g` : 'Sob Medida'}</strong>
                </div>

                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                  <span className="text-slate-400 block text-[10px] font-fredoka">Altura de Camada:</span>
                  <strong className="text-slate-800">{selectedProduct.specs?.layerHeight || '0.12mm (Ultra Definição)'}</strong>
                </div>

                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                  <span className="text-slate-400 block text-[10px] font-fredoka">Preenchimento (Infill):</span>
                  <strong className="text-slate-800">{selectedProduct.specs?.infill || '20% Gyroid Resistente'}</strong>
                </div>

                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                  <span className="text-slate-400 block text-[10px] font-fredoka">Tempo Estimado:</span>
                  <strong className="text-mimoo-purple font-fredoka font-bold">~{finalPrintHours}h de Extrusão</strong>
                </div>

                <div className="bg-slate-50 p-3 rounded-xl border border-slate-200">
                  <span className="text-slate-400 block text-[10px] font-fredoka">Escala Ativa:</span>
                  <strong className="text-slate-800">{selectedSize?.label || 'Padrão (12cm)'}</strong>
                </div>

              </div>
            )}

            {/* Tab 3: Care */}
            {activeTab === 'care' && (
              <div className="bg-slate-50/70 p-4 rounded-2xl border border-slate-200 animate-in fade-in">
                <ul className="space-y-2 text-xs sm:text-sm text-slate-600 font-nunito">
                  {(selectedProduct.careInstructions && selectedProduct.careInstructions.length > 0) ? (
                    selectedProduct.careInstructions.map((item, idx) => (
                      <li key={idx} className="flex items-start gap-2">
                        <span className="text-mimoo-pink font-bold flex-shrink-0">🌸</span>
                        <span>{item}</span>
                      </li>
                    ))
                  ) : (
                    <>
                      <li className="flex items-start gap-2">
                        <span className="text-mimoo-pink font-bold flex-shrink-0">🌸</span>
                        <span>Peça fabricada em polímero técnico ecológico: resistente e durável para decoração e colecionismo.</span>
                      </li>
                      <li className="flex items-start gap-2">
                        <span className="text-mimoo-pink font-bold flex-shrink-0">🌸</span>
                        <span>Evite expor diretamente a fontes de calor extremo acima de 55°C (como água fervente ou painel fechado de automóvel ao sol).</span>
                      </li>
                      <li className="flex items-start gap-2">
                        <span className="text-mimoo-pink font-bold flex-shrink-0">🌸</span>
                        <span>Para limpeza, utilize apenas pano levemente umedecido ou água corrente fria com sabão neutro.</span>
                      </li>
                    </>
                  )}
                </ul>
              </div>
            )}

          </div>

        </div>

      </div>

    </div>
  );
};

export default ProductDetailModal;
