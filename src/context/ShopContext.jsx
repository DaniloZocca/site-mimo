import React, { createContext, useContext, useState, useEffect } from 'react';
import confetti from 'canvas-confetti';
import { PRODUCTS, MOCK_ORDERS, FILAMENT_COLORS } from '../data/mockProducts';
import { useCart } from './CartContext';
import { API_BASE } from '../config/api';

const ShopContext = createContext();

export const ShopProvider = ({ children }) => {
  const cartCtx = useCart();
  const [products, setProducts] = useState([]);

  // Catálogo: busca ao abrir, a cada 60 s só com a aba visível e ao voltar para a aba
  // (no máximo uma vez a cada 15 s), sem requisições sobrepostas.
  useEffect(() => {
    const REFRESH_INTERVAL_MS = 60000;
    const MIN_REFETCH_GAP_MS = 15000;
    const REQUEST_TIMEOUT_MS = 15000;
    let controller = null;
    let lastFetchAt = 0;

    const fetchProducts = () => {
      if (controller) return;
      controller = new AbortController();
      lastFetchAt = Date.now();
      // Requisição pendurada não pode travar as próximas atualizações.
      const timeoutId = setTimeout(() => controller?.abort(), REQUEST_TIMEOUT_MS);
      fetch(`${API_BASE}/products`, { signal: controller.signal })
        .then((res) => {
          if (!res.ok) throw new Error('API offline');
          return res.json();
        })
        .then((resData) => {
          const list = Array.isArray(resData) 
            ? resData 
            : (Array.isArray(resData?.data) ? resData.data : []);
          setProducts(list);
        })
        .catch((err) => {
          if (err.name !== 'AbortError') console.warn('Backend API offline:', err);
        })
        .finally(() => {
          clearTimeout(timeoutId);
          controller = null;
        });
    };

    const refetchIfStale = () => {
      if (document.visibilityState === 'hidden') return;
      if (Date.now() - lastFetchAt < MIN_REFETCH_GAP_MS) return;
      fetchProducts();
    };

    const onInterval = () => {
      if (document.visibilityState === 'hidden') return;
      fetchProducts();
    };

    fetchProducts();
    const interval = setInterval(onInterval, REFRESH_INTERVAL_MS);
    window.addEventListener('focus', refetchIfStale);
    document.addEventListener('visibilitychange', refetchIfStale);
    return () => {
      clearInterval(interval);
      window.removeEventListener('focus', refetchIfStale);
      document.removeEventListener('visibilitychange', refetchIfStale);
      if (controller) controller.abort();
    };
  }, []);

  const [favorites, setFavorites] = useState([]);
  const [selectedProduct, setSelectedProduct] = useState(null);
  // Link de rastreio (ex.: botão "Acompanhar" do e-mail, /?token=...) abre direto na aba de rastreio.
  const [activeTab, setActiveTab] = useState(() => {
    const params = new URLSearchParams(window.location.search);
    return params.get('token') || params.get('order') || params.get('order_number') ? 'tracking' : 'home';
  }); // 'home' | 'catalog' | 'tracking' | 'howItWorks'
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  
  // Tracking
  const [trackingIdInput, setTrackingIdInput] = useState('MIMOO-8492');
  const [activeOrder, setActiveOrder] = useState(MOCK_ORDERS[0]);

  // Auth: ninguém começa logado; o login de verdade chega no M-26.
  const [isAuthModalOpen, setIsAuthModalOpen] = useState(false);
  const [user, setUser] = useState({
    name: '',
    email: '',
    isLoggedIn: false,
    ordersCount: 0,
  });

  // Notification Toast
  const [toast, setToast] = useState(null);

  const showToast = (message, type = 'success') => {
    setToast({ message, type, id: Date.now() });
    setTimeout(() => {
      setToast(null);
    }, 4000);
  };

  // Delegated addToCart with Toast notification
  const handleAddToCart = (productOrPayload, selectedColor, selectedSize, quantity = 1, selectedMaterial = null) => {
    cartCtx.addToCart(productOrPayload, selectedColor, selectedSize, quantity, selectedMaterial);
    const title = productOrPayload?.title || productOrPayload?.name || productOrPayload?.product?.title || 'Item';
    showToast(`✨ "${title}" adicionado à sacola de encomenda!`);
  };

  const handleRemoveFromCart = (itemId) => {
    cartCtx.removeFromCart(itemId);
    showToast('Item removido da sacola', 'info');
  };

  const toggleFavorite = (productId) => {
    setFavorites(prev => {
      const exists = prev.includes(productId);
      if (exists) {
        showToast('Removido dos favoritos', 'info');
        return prev.filter(id => id !== productId);
      } else {
        showToast('❤️ Adicionado aos seus favoritos!');
        return [...prev, productId];
      }
    });
  };

  const openProductDetail = (product) => {
    setSelectedProduct(product);
  };

  const closeProductDetail = () => {
    setSelectedProduct(null);
  };

  const triggerConfetti = () => {
    confetti({
      particleCount: 100,
      spread: 70,
      origin: { y: 0.6 }
    });
  };

  return (
    <ShopContext.Provider value={{
      // Cart state from CartContext
      cart: cartCtx.cart,
      cartItems: cartCtx.cart,
      setCart: cartCtx.setCart,
      addToCart: handleAddToCart,
      removeFromCart: handleRemoveFromCart,
      updateCartQuantity: cartCtx.updateQuantity,
      updateQuantity: cartCtx.updateQuantity,
      clearCart: cartCtx.clearCart,
      isCartOpen: cartCtx.isCartOpen,
      setIsCartOpen: cartCtx.setIsCartOpen,
      isCheckoutOpen: cartCtx.isCheckoutOpen,
      setIsCheckoutOpen: cartCtx.setIsCheckoutOpen,
      selectedShipping: cartCtx.selectedShipping,
      setSelectedShipping: cartCtx.setSelectedShipping,
      subtotalCents: cartCtx.subtotalCents,
      cartSubtotal: cartCtx.cartSubtotal,
      totalWeightGrams: cartCtx.totalWeightGrams,
      totalPrintTimeHours: cartCtx.totalPrintTimeHours,
      cartTotalPrintHours: cartCtx.cartTotalPrintHours,
      cartEstimatedDays: cartCtx.cartEstimatedDays,
      cartItemCount: cartCtx.cartItemCount,
      cartCount: cartCtx.cartCount,
      calculateTotal: () => cartCtx.cartSubtotal,
      calculateTotalPrintHours: () => cartCtx.totalPrintTimeHours,

      // Catalog & Navigation
      products,
      selectedProduct,
      openProductDetail,
      closeProductDetail,
      activeTab,
      setActiveTab,
      selectedCategory,
      setSelectedCategory,
      searchQuery,
      setSearchQuery,
      favorites,
      toggleFavorite,
      triggerConfetti,
      trackingIdInput,
      setTrackingIdInput,
      activeOrder,
      setActiveOrder,
      isAuthModalOpen,
      setIsAuthModalOpen,
      user,
      setUser,
      toast,
      showToast
    }}>
      {children}
    </ShopContext.Provider>
  );
};

export const useShop = () => {
  const context = useContext(ShopContext);
  if (!context) {
    throw new Error('useShop must be used within a ShopProvider');
  }
  return context;
};
