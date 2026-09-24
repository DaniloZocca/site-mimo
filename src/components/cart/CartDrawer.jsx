import React, { useState } from 'react';
import { useCart } from '../../context/CartContext';
import { useShop } from '../../context/ShopContext';
import { ShippingSelector } from './ShippingSelector';
import { 
  X, 
  Trash2, 
  Plus, 
  Minus, 
  ShoppingBag, 
  Clock, 
  Sparkles, 
  ArrowRight, 
  ShieldCheck, 
  Truck,
  Layers,
  Scale
} from 'lucide-react';

/**
 * Gaveta Lateral do Carrinho (CartDrawer) - MIMOO LAB 3D Studio
 * Suporta chave composta de variações (Produto + Material + Cor + Tamanho)
 * com métricas fabris em tempo real.
 */
export const CartDrawer = () => {
  // Consome estado do CartContext
  const { 
    cart, 
    isCartOpen, 
    setIsCartOpen, 
    updateQuantity, 
    removeFromCart, 
    subtotalCents, 
    cartSubtotal,
    totalPrintTimeHours, 
    cartTotalPrintHours,
    totalWeightGrams,
    cartEstimatedDays,
    selectedShipping,
    setSelectedShipping,
  } = useCart();

  // Consome ações globais (Checkout Modal e Toasts)
  const { 
    setIsCheckoutOpen, 
    showToast 
  } = useShop();

  const [couponCode, setCouponCode] = useState('');
  const [appliedDiscount, setAppliedDiscount] = useState(0); // 0 a 0.5 (ex: 0.10 para 10%)
  const [couponMessage, setCouponMessage] = useState('');

  if (!isCartOpen) return null;

  const handleProceedToCheckout = () => {
    if (cart.length === 0) {
      if (showToast) showToast('Sua sacola de encomendas está vazia!', 'info');
      return;
    }
    setIsCartOpen(false);
    setIsCheckoutOpen(true);
  };

  const handleApplyCoupon = (e) => {
    e.preventDefault();
    const code = couponCode.trim().toUpperCase();
    if (code === 'MIMOO10' || code === 'PRIMEIRACOMPRA') {
      setAppliedDiscount(0.10);
      setCouponMessage('Cupom de 10% OFF aplicado com sucesso! 🎉');
      if (showToast) showToast('🎉 Cupom de 10% OFF ativado!');
    } else {
      setAppliedDiscount(0);
      setCouponMessage('Cupom inválido ou expirado.');
      if (showToast) showToast('Cupom não encontrado', 'info');
    }
  };

  const discountAmount = cartSubtotal * appliedDiscount;
  const isFreeShipping = cartSubtotal >= 199;
  const shippingPrice = selectedShipping ? (selectedShipping.price ?? ((selectedShipping.price_in_cents || 0) / 100)) : 0;
  const shippingCost = isFreeShipping || cart.length === 0 ? 0 : shippingPrice;
  const finalTotal = Math.max(0, cartSubtotal - discountAmount + shippingCost);

  return (
    <div className="fixed inset-0 z-50 overflow-hidden animate-in fade-in duration-200">
      {/* Backdrop com Blur */}
      <div 
        onClick={() => setIsCartOpen(false)}
        className="absolute inset-0 bg-mimoo-dark/60 backdrop-blur-sm transition-opacity"
        aria-hidden="true"
      />

      {/* Drawer Container */}
      <div className="absolute inset-y-0 right-0 max-w-full flex pl-6 sm:pl-10">
        <div className="w-screen max-w-md bg-white border-l-3 border-mimoo-dark shadow-chunky-lg flex flex-col justify-between animate-in slide-in-from-right duration-300">
          
          {/* Header */}
          <div className="p-4 sm:p-5 border-b-2 border-mimoo-dark/20 flex items-center justify-between bg-mimoo-purple-tint">
            <div className="flex items-center gap-2.5">
              <div className="w-10 h-10 rounded-2xl bg-mimoo-purple text-white border-2 border-mimoo-dark shadow-chunky-sm flex items-center justify-center">
                <ShoppingBag className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-fredoka font-bold text-lg text-mimoo-dark flex items-center gap-2">
                  <span>Sacola de Encomendas</span>
                </h3>
                <span className="text-xs text-slate-500 font-nunito block">
                  {cart.length} {cart.length === 1 ? 'item configurado' : 'itens configurados'}
                </span>
              </div>
            </div>

            <button
              onClick={() => setIsCartOpen(false)}
              className="p-2 rounded-xl border-2 border-mimoo-dark bg-white shadow-chunky-sm hover:bg-mimoo-pink-light transition-all"
              title="Fechar Gaveta"
            >
              <X className="w-5 h-5 text-mimoo-dark" />
            </button>
          </div>

          {/* Cart Items List / Body */}
          <div className="flex-1 overflow-y-auto p-4 sm:p-5 space-y-4">
            
            {cart.length > 0 ? (
              <>
                {/* 🌟 CUMULATIVE PRINT TIME CALLOUT IN CART 🌟 */}
                <div className="rounded-2xl border-2 border-mimoo-dark bg-gradient-to-r from-mimoo-yellow-light via-amber-50 to-amber-100 p-3.5 shadow-chunky-sm space-y-1.5">
                  <div className="flex items-center justify-between font-fredoka font-bold text-xs text-mimoo-dark">
                    <span className="flex items-center gap-1.5">
                      <Clock className="w-4 h-4 text-mimoo-purple flex-shrink-0" />
                      Fila Total de Impressão na Oficina:
                    </span>
                    <strong className="text-mimoo-purple text-sm">~{totalPrintTimeHours}h</strong>
                  </div>
                  <div className="flex items-center justify-between text-[11px] text-slate-600 font-nunito">
                    <span>Prazo Fabril Estimado:</span>
                    <strong className="text-slate-800 font-bold">~{cartEstimatedDays} {cartEstimatedDays === 1 ? 'dia útil' : 'dias úteis'}</strong>
                  </div>
                  <div className="flex items-center justify-between text-[11px] text-slate-600 font-nunito pt-1 border-t border-amber-200/60">
                    <span className="flex items-center gap-1">
                      <Scale className="w-3.5 h-3.5 text-slate-500" />
                      Peso Total dos Polímeros:
                    </span>
                    <strong className="text-slate-800 font-bold">{totalWeightGrams}g</strong>
                  </div>
                </div>

                {/* Free Shipping Progress Bar */}
                <div className="bg-slate-50 p-3 rounded-2xl border border-slate-200 text-xs font-fredoka space-y-1.5">
                  <div className="flex justify-between text-slate-700 text-[11px]">
                    <span className="flex items-center gap-1">
                      <Truck className="w-3.5 h-3.5 text-mimoo-purple" />
                      {isFreeShipping 
                        ? '🎉 Parabéns! Você ganhou Frete Grátis!' 
                        : `Faltam R$ ${(199 - cartSubtotal).toFixed(2).replace('.', ',')} para Frete Grátis`}
                    </span>
                  </div>
                  <div className="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                    <div 
                      className="bg-mimoo-mint h-full transition-all duration-300"
                      style={{ width: `${Math.min(100, (cartSubtotal / 199) * 100)}%` }}
                    />
                  </div>
                </div>

                {/* Items Container */}
                <div className="space-y-3">
                  {cart.map((item) => {
                    const itemSubtotal = ((item.unitTotalCents * item.quantity) / 100);
                    const itemUnit = (item.unitTotalCents / 100);

                    return (
                      <div 
                        key={item.cartItemId || item.id}
                        className="p-3.5 rounded-2xl border-2 border-mimoo-dark bg-white shadow-chunky-sm flex flex-col gap-2.5 transition-all hover:border-mimoo-purple"
                      >
                        <div className="flex items-start gap-3">
                          {/* Miniatura da Peça */}
                          <div className="relative w-16 h-16 sm:w-20 sm:h-20 rounded-2xl border-2 border-mimoo-dark/20 overflow-hidden bg-slate-50 flex-shrink-0">
                            <img 
                              src={item.imageUrl || item.image || '/assets/products/cow_planter.jpg'} 
                              alt={item.title} 
                              className="w-full h-full object-cover"
                            />
                          </div>

                          {/* Detalhes da Peça & Badges Técnicos */}
                          <div className="flex-1 min-w-0 space-y-1.5">
                            <div className="flex items-start justify-between gap-1">
                              <h4 className="font-fredoka font-bold text-sm text-mimoo-dark truncate leading-snug">
                                {item.title}
                              </h4>

                              {/* Botão Remover */}
                              <button
                                onClick={() => removeFromCart(item.cartItemId || item.id)}
                                className="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors flex-shrink-0"
                                title="Remover item da sacola"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </div>
                            
                            {/* Badges Técnicos Visuais: Material + Cor + Tamanho */}
                            <div className="flex items-center gap-1.5 flex-wrap text-[11px] font-nunito">
                              {/* 1. Badge do Material (Pílula Roxa Sutil) */}
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-purple-50 text-mimoo-purple border border-purple-200 font-fredoka font-bold text-[10px]">
                                <Layers className="w-2.5 h-2.5" />
                                {item.material?.name || 'PLA'}
                              </span>

                              {/* 2. Círculo de Amostra de Cor (Swatch) */}
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-50 text-slate-700 border border-slate-200 font-fredoka font-medium text-[10px]">
                                <span 
                                  className="w-2.5 h-2.5 rounded-full border border-mimoo-dark/30 inline-block shadow-sm"
                                  style={{ background: item.color?.hexCode || '#A855F7' }}
                                />
                                <span className="truncate max-w-[80px]">{item.color?.name || 'Cor'}</span>
                              </span>

                              {/* 3. Badge da Escala/Tamanho */}
                              <span className="inline-flex items-center px-2 py-0.5 rounded-md bg-slate-50 text-slate-600 border border-slate-200 text-[10px] font-fredoka font-medium truncate max-w-[100px]">
                                {item.size?.name || 'Padrão'}
                              </span>
                            </div>

                            {/* Tempo de Extrusão Individual */}
                            <div className="flex items-center gap-1.5 text-[10px] text-mimoo-purple font-fredoka">
                              <Clock className="w-3 h-3 flex-shrink-0" />
                              <span>~{item.estimatedPrintTimeHours * item.quantity}h de extrusão 3D</span>
                            </div>
                          </div>
                        </div>

                        {/* Preço e Seletor de Quantidade */}
                        <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-xs font-nunito">
                          <div>
                            <span className="text-[10px] text-slate-400 block font-nunito">
                              {item.quantity > 1 ? `${item.quantity}x de R$ ${itemUnit.toFixed(2).replace('.', ',')}` : 'Valor Unitário'}
                            </span>
                            <span className="font-fredoka font-bold text-sm text-mimoo-dark">
                              R$ {itemSubtotal.toFixed(2).replace('.', ',')}
                            </span>
                          </div>

                          {/* Controles de Quantidade Táteis (+ / -) */}
                          <div className="flex items-center rounded-xl border-2 border-mimoo-dark bg-white shadow-chunky-sm overflow-hidden p-0.5">
                            <button
                              onClick={() => updateQuantity(item.cartItemId || item.id, item.quantity - 1)}
                              className="w-7 h-7 rounded-lg font-fredoka font-bold text-slate-700 hover:bg-slate-100 flex items-center justify-center text-sm disabled:opacity-40"
                              title="Diminuir quantidade"
                            >
                              <Minus className="w-3 h-3" />
                            </button>
                            
                            <span className="w-7 text-center font-fredoka font-bold text-xs text-mimoo-dark">
                              {item.quantity}
                            </span>

                            <button
                              onClick={() => updateQuantity(item.cartItemId || item.id, item.quantity + 1)}
                              disabled={item.quantity >= 50}
                              className="w-7 h-7 rounded-lg font-fredoka font-bold text-slate-700 hover:bg-slate-100 flex items-center justify-center text-sm disabled:opacity-40"
                              title="Aumentar quantidade"
                            >
                              <Plus className="w-3 h-3" />
                            </button>
                          </div>
                        </div>

                      </div>
                    );
                  })}
                </div>

                {/* Cupom de Desconto */}
                <form onSubmit={handleApplyCoupon} className="space-y-1.5 pt-2">
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={couponCode}
                      onChange={(e) => setCouponCode(e.target.value)}
                      placeholder="Código de Cupom (ex: MIMOO10)"
                      className="flex-1 rounded-xl border-2 border-mimoo-dark bg-slate-50 px-3 py-2 text-xs font-fredoka uppercase focus:outline-none focus:bg-white"
                    />
                    <button
                      type="submit"
                      className="btn-chunky-white px-3.5 py-2 text-xs font-bold"
                    >
                      Aplicar
                    </button>
                  </div>
                  {couponMessage && (
                    <span className={`text-[10px] font-fredoka block ${appliedDiscount > 0 ? 'text-emerald-600 font-bold' : 'text-rose-500'}`}>
                      {couponMessage}
                    </span>
                  )}
                </form>

                {/* Seletor de Frete Volumétrico */}
                <div className="pt-2 border-t border-slate-200">
                  <ShippingSelector
                    items={cart}
                    compact
                    onShippingSelected={setSelectedShipping}
                    selectedOption={selectedShipping}
                  />
                </div>
              </>
            ) : (
              /* Empty Cart State */
              <div className="text-center py-16 space-y-4 animate-in fade-in">
                <div className="w-16 h-16 rounded-3xl bg-mimoo-purple-tint border-2 border-mimoo-dark flex items-center justify-center mx-auto text-3xl shadow-chunky-sm">
                  🐮
                </div>
                <div className="space-y-1">
                  <h4 className="font-fredoka font-bold text-lg text-mimoo-dark">
                    Sua sacola está vazia!
                  </h4>
                  <p className="text-xs text-slate-500 font-nunito max-w-xs mx-auto">
                    Explore nossas criações de manufatura aditiva e personalize as cores e materiais do seu gosto.
                  </p>
                </div>
                <button
                  onClick={() => setIsCartOpen(false)}
                  className="btn-chunky-purple px-5 py-2.5 text-xs font-bold"
                >
                  Explorar Catálogo 3D
                </button>
              </div>
            )}

          </div>

          {/* Footer / Finalização de Encomenda */}
          {cart.length > 0 && (
            <div className="p-4 sm:p-5 border-t-2 border-mimoo-dark/20 bg-slate-50 space-y-3">
              <div className="space-y-2 text-xs font-fredoka">
                {/* 1. Subtotal das Peças 3D */}
                <div className="flex justify-between text-slate-600">
                  <span>Subtotal das Peças 3D:</span>
                  <span className="font-bold text-slate-800">
                    R$ {cartSubtotal.toFixed(2).replace('.', ',')}
                  </span>
                </div>

                {appliedDiscount > 0 && (
                  <div className="flex justify-between text-emerald-600 font-bold">
                    <span>Desconto Cupom (10%):</span>
                    <span>- R$ {discountAmount.toFixed(2).replace('.', ',')}</span>
                  </div>
                )}

                {/* 2. Frete (SEDEX / PAC) */}
                <div className="flex justify-between text-slate-600">
                  <span>
                    Frete ({selectedShipping?.service_name ? selectedShipping.service_name : 'SEDEX / PAC'}):
                  </span>
                  <span>
                    {isFreeShipping ? (
                      <strong className="text-emerald-600">GRÁTIS</strong>
                    ) : selectedShipping ? (
                      `R$ ${shippingCost.toFixed(2).replace('.', ',')}`
                    ) : (
                      <span className="text-slate-400 font-nunito text-[11px]">Calcular acima</span>
                    )}
                  </span>
                </div>

                {/* 3. Total Estimado */}
                <div className="flex justify-between text-base font-bold text-mimoo-dark pt-2 border-t border-slate-200">
                  <span>Total Estimado:</span>
                  <span className="text-mimoo-purple text-lg font-black font-fredoka">
                    R$ {finalTotal.toFixed(2).replace('.', ',')}
                  </span>
                </div>

                {/* 4. Prazo Consolidado */}
                <div className="p-2.5 rounded-xl bg-purple-50/80 border border-purple-200/80 text-[11px] font-nunito text-slate-600 flex items-start gap-2 shadow-sm">
                  <Clock className="w-4 h-4 text-mimoo-purple flex-shrink-0 mt-0.5" />
                  <div>
                    {selectedShipping ? (
                      <span className="text-slate-700 leading-snug block">
                        <strong className="text-mimoo-dark font-fredoka font-bold">
                          Prazo Consolidado: {selectedShipping.total_estimated_days} dias úteis
                        </strong>{' '}
                        ({selectedShipping.manufacturing_lead_time_days || cartEstimatedDays} dias fabricação + {selectedShipping.carrier_delivery_days} dias transporte).
                      </span>
                    ) : (
                      <span className="text-slate-600 leading-snug block">
                        <strong className="text-mimoo-dark font-fredoka font-bold">
                          Prazo Consolidado: ~{cartEstimatedDays} dias úteis
                        </strong>{' '}
                        ({cartEstimatedDays} dias fabricação + transporte a calcular).
                      </span>
                    )}
                  </div>
                </div>
              </div>

              {/* Botão Avançar para Checkout */}
              <button
                onClick={handleProceedToCheckout}
                className="w-full btn-chunky-purple py-3.5 px-6 text-sm sm:text-base font-bold flex items-center justify-center gap-2 shadow-chunky hover:scale-[1.01] transition-transform"
              >
                <Sparkles className="w-4 h-4 text-mimoo-yellow" />
                <span>Avançar para Checkout</span>
                <ArrowRight className="w-4 h-4" />
              </button>

              <div className="flex items-center justify-center gap-1.5 text-[10px] text-slate-400 font-nunito pt-0.5">
                <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                <span>Garantia de Manufatura MIMOO LAB • Checkout Criptografado</span>
              </div>
            </div>
          )}

        </div>
      </div>
    </div>
  );
};

export default CartDrawer;
