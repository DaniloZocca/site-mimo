import React, { createContext, useContext, useState, useEffect, useMemo } from 'react';

const CartContext = createContext(null);

const CART_STORAGE_KEY = 'mimoo_cart_v2';
const SHIPPING_STORAGE_KEY = 'mimoo_shipping_v2';

/**
 * Normaliza qualquer formato de payload em um item de carrinho padronizado com chave composta única:
 * ${productId}_${material.id}_${color.id}_${size.id}
 *
 * @param {Object} payload 
 * @param {number} [fallbackQty=1]
 * @returns {Object}
 */
export const normalizeCartItem = (payload, fallbackQty = 1) => {
  const product = payload.product || payload;
  const productId = String(product.id || product.productId || 'prod-default');

  // Material normalizado
  const rawMaterial = payload.material || payload.selectedMaterial || product.materials?.[0] || {
    id: 'pla',
    name: 'PLA',
    full_name: 'PLA Premium (Atóxico e Biodegradável)',
  };
  const material = {
    id: String(rawMaterial.id || 'pla').toLowerCase(),
    name: rawMaterial.name || 'PLA',
    fullName: rawMaterial.fullName || rawMaterial.full_name || rawMaterial.name || 'PLA Premium',
  };

  // Cor normalizada
  const rawColor = payload.color || payload.selectedColor || product.colors?.[0] || {
    id: 'default',
    name: 'Cor Padrão',
    hex_code: '#A855F7',
  };
  const color = {
    id: String(rawColor.id || 'default'),
    name: rawColor.name || 'Cor Padrão',
    hexCode: rawColor.hexCode || rawColor.hex_code || rawColor.hex || '#A855F7',
  };

  // Tamanho normalizado
  const rawSize = payload.size || payload.selectedSize || product.sizeOptions?.[0] || product.sizes?.[0] || {
    id: 'p',
    name: 'Padrão (12cm)',
    price_modifier_cents: 0,
  };
  const sizePriceModifierCents = Number(
    rawSize.priceModifierCents ?? 
    rawSize.price_modifier_cents ?? 
    ((rawSize.addPrice || 0) * 100) ?? 
    0
  );
  const size = {
    id: String(rawSize.id || 'p'),
    name: rawSize.name || rawSize.label || 'Padrão (12cm)',
    priceModifierCents: sizePriceModifierCents,
  };

  // Quantidade (1 a 50)
  const quantity = Math.min(50, Math.max(1, Number(payload.quantity ?? fallbackQty ?? 1)));

  // Chave Composta Determinística
  const cartItemId = payload.cartItemId || `${productId}_${material.id}_${color.id}_${size.id}`;

  // Preços em Centavos
  const unitBasePriceCents = Number(
    product.base_price_cents ?? 
    product.unitBasePriceCents ?? 
    ((product.price || 0) * 100) ?? 
    0
  );
  const unitTotalCents = unitBasePriceCents + sizePriceModifierCents;

  // Tempo de Impressão e Peso
  const estimatedPrintTimeHours = Number(
    product.estimated_print_time_hours ?? 
    product.estimatedPrintTimeHours ?? 
    product.printTimeHours ?? 
    product.print_time_hours ?? 
    14
  );
  const weightGrams = Number(
    product.weight_grams ?? 
    product.weightGrams ?? 
    120
  );

  const title = product.title || product.name || 'Criação 3D MIMOO';
  const slug = product.slug || '';
  const imageUrl = product.images?.[0]?.image_path || 
                   product.image || 
                   product.imageUrl || 
                   '/assets/products/cow_planter.jpg';

  return {
    cartItemId,
    id: cartItemId, // Compatibilidade retroativa
    productId,
    title,
    name: title, // Compatibilidade
    slug,
    imageUrl,
    image: imageUrl, // Compatibilidade
    material,
    selectedMaterial: { // Compatibilidade
      id: material.id,
      name: material.name,
      full_name: material.fullName,
    },
    color,
    selectedColor: { // Compatibilidade
      id: color.id,
      name: color.name,
      hex: color.hexCode,
      hexCode: color.hexCode,
    },
    size: {
      ...size,
      label: size.name,
    },
    selectedSize: { // Compatibilidade
      id: size.id,
      label: size.name,
      name: size.name,
      addPrice: sizePriceModifierCents / 100,
      priceModifierCents: sizePriceModifierCents,
    },
    unitBasePriceCents,
    unitTotalCents,
    unitPrice: unitTotalCents / 100, // Preço em Reais
    estimatedPrintTimeHours,
    printHours: estimatedPrintTimeHours + (rawSize.addTime || 0), // Compatibilidade
    weightGrams,
    weight_grams: weightGrams, // Compatibilidade
    quantity,
    product, // Referência do produto original
  };
};

export const CartProvider = ({ children }) => {
  // Inicialização com LocalStorage para persistência da sacola
  const [cart, setCart] = useState(() => {
    try {
      const saved = localStorage.getItem(CART_STORAGE_KEY);
      if (saved) {
        const parsed = JSON.parse(saved);
        if (Array.isArray(parsed)) {
          return parsed.map((item) => normalizeCartItem(item, item.quantity));
        }
      }
    } catch (e) {
      console.warn('Erro ao carregar carrinho do LocalStorage:', e);
    }
    return [];
  });

  // Estado global do frete selecionado (PAC, SEDEX, etc.)
  const [selectedShipping, setSelectedShipping] = useState(() => {
    try {
      const savedShipping = localStorage.getItem(SHIPPING_STORAGE_KEY);
      if (savedShipping) {
        return JSON.parse(savedShipping);
      }
    } catch (e) {
      console.warn('Erro ao carregar frete do LocalStorage:', e);
    }
    return null;
  });

  const [isCartOpen, setIsCartOpen] = useState(false);
  const [isCheckoutOpen, setIsCheckoutOpen] = useState(false);

  // Sincroniza estado com LocalStorage
  useEffect(() => {
    try {
      localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart));
    } catch (e) {
      console.warn('Erro ao salvar carrinho no LocalStorage:', e);
    }
  }, [cart]);

  // Sincroniza frete com LocalStorage
  useEffect(() => {
    try {
      if (selectedShipping) {
        localStorage.setItem(SHIPPING_STORAGE_KEY, JSON.stringify(selectedShipping));
      } else {
        localStorage.removeItem(SHIPPING_STORAGE_KEY);
      }
    } catch (e) {
      console.warn('Erro ao salvar frete no LocalStorage:', e);
    }
  }, [selectedShipping]);

  /**
   * Adiciona um item ao carrinho.
   * Suporta tanto payload de objeto estruturado quanto chamada com argumentos individuais:
   * addToCart(payload) OU addToCart(product, color, size, quantity, material)
   */
  const addToCart = (payloadOrProduct, maybeColor = null, maybeSize = null, maybeQuantity = 1, maybeMaterial = null) => {
    let normalized;

    if (payloadOrProduct && typeof payloadOrProduct === 'object' && (payloadOrProduct.productId || payloadOrProduct.product)) {
      // Formato objeto de payload
      normalized = normalizeCartItem(payloadOrProduct, payloadOrProduct.quantity || 1);
    } else {
      // Formato argumentos individuais legados
      normalized = normalizeCartItem({
        product: payloadOrProduct,
        color: maybeColor,
        size: maybeSize,
        quantity: maybeQuantity,
        material: maybeMaterial,
      }, maybeQuantity);
    }

    setCart((prevCart) => {
      const existingIndex = prevCart.findIndex((item) => item.cartItemId === normalized.cartItemId);

      if (existingIndex > -1) {
        // Incrementa quantidade do item existente respeitando o teto de 50
        const updated = [...prevCart];
        const newQty = Math.min(50, updated[existingIndex].quantity + normalized.quantity);
        updated[existingIndex] = {
          ...updated[existingIndex],
          quantity: newQty,
        };
        return updated;
      }

      // Adiciona novo item à lista
      return [...prevCart, normalized];
    });

    setIsCartOpen(true);
  };

  /**
   * Atualiza a quantidade do item especificado por cartItemId (mínimo 1, máximo 50).
   */
  const updateQuantity = (cartItemId, newQuantity) => {
    const qty = Number(newQuantity);
    if (qty <= 0) {
      removeFromCart(cartItemId);
      return;
    }

    const clampedQty = Math.min(50, Math.max(1, qty));
    setCart((prevCart) =>
      prevCart.map((item) =>
        item.cartItemId === cartItemId ? { ...item, quantity: clampedQty } : item
      )
    );
  };

  // Alias retroativo
  const updateCartQuantity = updateQuantity;

  /**
   * Remove o item específico do carrinho por cartItemId.
   */
  const removeFromCart = (cartItemId) => {
    setCart((prevCart) => prevCart.filter((item) => item.cartItemId !== cartItemId && item.id !== cartItemId));
  };

  /**
   * Limpa todos os itens do carrinho após conclusão da compra e reseta o frete.
   */
  const clearCart = () => {
    setCart([]);
    setSelectedShipping(null);
    try {
      localStorage.removeItem(CART_STORAGE_KEY);
      localStorage.removeItem(SHIPPING_STORAGE_KEY);
    } catch (e) {
      console.warn('Erro ao limpar carrinho do LocalStorage:', e);
    }
  };

  // Métricas Calculadas em Centavos e Unidades Fabris
  const {
    subtotalCents,
    totalWeightGrams,
    totalPrintTimeHours,
    cartItemCount,
  } = useMemo(() => {
    return cart.reduce(
      (acc, item) => {
        const qty = Number(item.quantity) || 1;
        acc.subtotalCents += (Number(item.unitTotalCents) || 0) * qty;
        acc.totalWeightGrams += (Number(item.weightGrams) || 0) * qty;
        acc.totalPrintTimeHours += (Number(item.estimatedPrintTimeHours) || 0) * qty;
        acc.cartItemCount += qty;
        return acc;
      },
      {
        subtotalCents: 0,
        totalWeightGrams: 0,
        totalPrintTimeHours: 0,
        cartItemCount: 0,
      }
    );
  }, [cart]);

  // Valores em Reais para compatibilidade
  const cartSubtotal = subtotalCents / 100;
  const cartTotalPrintHours = totalPrintTimeHours;
  const cartEstimatedDays = Math.max(1, Math.ceil(totalPrintTimeHours / 12));

  return (
    <CartContext.Provider
      value={{
        cart,
        cartItems: cart,
        setCart,
        addToCart,
        updateQuantity,
        updateCartQuantity,
        removeFromCart,
        clearCart,
        isCartOpen,
        setIsCartOpen,
        isCheckoutOpen,
        setIsCheckoutOpen,
        selectedShipping,
        setSelectedShipping,
        subtotalCents,
        cartSubtotal,
        totalWeightGrams,
        totalPrintTimeHours,
        cartTotalPrintHours,
        cartEstimatedDays,
        cartItemCount,
        cartCount: cartItemCount,
      }}
    >
      {children}
    </CartContext.Provider>
  );
};

export const useCart = () => {
  const context = useContext(CartContext);
  if (!context) {
    throw new Error('useCart deve ser utilizado dentro de um CartProvider');
  }
  return context;
};

export default CartContext;
