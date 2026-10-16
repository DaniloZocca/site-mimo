import React, { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react';
import confetti from 'canvas-confetti';
import { PRODUCTS, MOCK_ORDERS, FILAMENT_COLORS } from '../data/mockProducts';
import { useCart } from './CartContext';
import { API_BASE } from '../config/api';
import { accountApi } from '../utils/accountApi';

const ShopContext = createContext();

const LOGGED_OUT_USER = { name: '', email: '', isLoggedIn: false, emailVerified: false };

const readAccountLink = () => {
  const params = new URLSearchParams(window.location.search);
  return {
    verification: params.get('verificar') === '1'
      ? {
          id: params.get('id') || '',
          hash: params.get('hash') || '',
          expires: params.get('expires') || '',
          signature: params.get('signature') || '',
        }
      : null,
    resetToken: params.get('reset') || null,
  };
};

const toUser = (customer) => ({
  name: customer.name || '',
  email: customer.email || '',
  isLoggedIn: true,
  emailVerified: Boolean(customer.email_verified),
});

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

  // Conta de cliente (M-26): a sessão é um cookie da API; ao abrir a loja, GET /api/conta/me diz quem está logado.
  // Links dos e-mails de conta: ?verificar=1&id&hash&expires&signature (confirmação) e ?reset=<token> (nova senha)
  const [accountLink] = useState(readAccountLink);
  const [isAuthModalOpen, setIsAuthModalOpen] = useState(Boolean(accountLink.resetToken));
  const [authModalView, setAuthModalView] = useState(accountLink.resetToken ? 'reset' : 'login'); // 'login' | 'register' | 'forgot' | 'reset'
  const [user, setUser] = useState(LOGGED_OUT_USER);
  const [pendingVerification, setPendingVerification] = useState(null);
  const [resetToken, setResetToken] = useState(accountLink.resetToken);
  const verifyingRef = useRef(false);

  // Notification Toast
  const [toast, setToast] = useState(null);

  const showToast = useCallback((message, type = 'success') => {
    setToast({ message, type, id: Date.now() });
    setTimeout(() => {
      setToast(null);
    }, 4000);
  }, []);

  const applyCustomer = useCallback((customer) => {
    setUser(customer ? toUser(customer) : LOGGED_OUT_USER);
  }, []);

  // Confirmação de e-mail: só vale logada na própria conta (a API recusa outra conta ou sem login).
  const confirmPendingEmail = useCallback(async (verification) => {
    if (!verification || verifyingRef.current) return;
    verifyingRef.current = true;
    try {
      const { customer } = await accountApi.verifyEmail(verification);
      applyCustomer(customer);
      setPendingVerification(null);
      showToast('💜 E-mail confirmado! Seus pedidos já aparecem na sua conta.');
    } catch (err) {
      if (err.status === 401) {
        setAuthModalView('login');
        setIsAuthModalOpen(true);
        showToast('Entre na sua conta para confirmar o e-mail. 🐮', 'info');
      } else {
        setPendingVerification(null);
        showToast(err.message, 'info');
      }
    } finally {
      verifyingRef.current = false;
    }
  }, [applyCustomer, showToast]);

  useEffect(() => {
    const { verification, resetToken: linkResetToken } = accountLink;

    // Tira os dados do link da barra de endereço (não ficam no histórico nem num recarregar).
    if (verification || linkResetToken) {
      const params = new URLSearchParams(window.location.search);
      ['verificar', 'id', 'hash', 'expires', 'signature', 'reset'].forEach((key) => params.delete(key));
      const query = params.toString();
      window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
    }

    let cancelled = false;
    accountApi.me()
      .then(({ customer }) => {
        if (cancelled) return;
        applyCustomer(customer);
        if (verification) confirmPendingEmail(verification);
      })
      .catch(() => {
        if (cancelled) return;
        applyCustomer(null);
        if (verification) {
          setPendingVerification(verification);
          setAuthModalView('login');
          setIsAuthModalOpen(true);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [accountLink, applyCustomer, confirmPendingEmail]);

  const login = async (credentials) => {
    const { customer } = await accountApi.login(credentials);
    applyCustomer(customer);
    if (pendingVerification) await confirmPendingEmail(pendingVerification);
    return customer;
  };

  const register = async (data) => {
    const { customer } = await accountApi.register(data);
    applyCustomer(customer);
    return customer;
  };

  const logout = async () => {
    try {
      await accountApi.logout();
    } finally {
      applyCustomer(null);
    }
  };

  const refreshUser = async () => {
    try {
      const { customer } = await accountApi.me();
      applyCustomer(customer);
    } catch {
      applyCustomer(null);
    }
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
      authModalView,
      setAuthModalView,
      user,
      login,
      register,
      logout,
      refreshUser,
      resetToken,
      setResetToken,
      pendingVerification,
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
