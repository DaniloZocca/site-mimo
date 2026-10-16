import React, { useState, useEffect, useRef } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import confetti from 'canvas-confetti';
import { 
  X, 
  ArrowLeft, 
  ArrowRight, 
  User, 
  Truck, 
  CreditCard, 
  QrCode, 
  Sparkles, 
  ShieldCheck, 
  Clock, 
  CheckCircle2, 
  Copy, 
  Check, 
  Loader2, 
  AlertCircle,
  Package,
  Layers,
  Info
} from 'lucide-react';
import { useShop } from '../../context/ShopContext';
import { checkoutFormSchema, transformToApiPayload } from '../../schemas/checkoutSchema';
import { formatCPF, formatPhone, formatCEP, cleanNonDigits } from '../../utils/validators';
import { useViaCep } from '../../hooks/useViaCep';
import { usePaymentStatus } from '../../hooks/usePaymentStatus';
import { useMercadoPagoCard } from '../../hooks/useMercadoPagoCard';
import { CreditCardPaymentStep } from './CreditCardPaymentStep';
import { ShippingSelector } from '../cart/ShippingSelector';
import { clearShippingCache, normalizeShippingOption } from '../../hooks/useShipping';
import { ShippingPriceChangedError, isShippingPriceChanged } from '../../utils/shippingPriceChange';
import { postCheckout, CheckoutInProgressError, PAYMENT_UNDER_REVIEW } from '../../utils/checkoutRequest';
import { useCheckoutAttemptKey } from '../../hooks/useCheckoutAttemptKey';
import { calculateCartTotals, formatMoney } from '../../utils/pricing';
import { API_BASE } from '../../config/api';

/**
 * Modal Principal de Finalização de Encomendas 3D (MIMOO LAB)
 * Orquestra o fluxo de checkout em 3 etapas com alternância dinâmica entre PIX e Cartão de Crédito.
 */
export const CheckoutModal = () => {
  const { 
    isCheckoutOpen, 
    setIsCheckoutOpen, 
    cart, 
    setCart,
    clearCart,
    cartTotalPrintHours, 
    user,
    showToast,
    setActiveTab,
    selectedShipping,
    setSelectedShipping
  } = useShop();

  const [currentStep, setCurrentStep] = useState(1); // 1: Dados, 2: Endereço, 3: Pagamento, 4: Tela PIX
  const [isSubmittingPix, setIsSubmittingPix] = useState(false);
  const [apiError, setApiError] = useState(null);
  const [shippingNotice, setShippingNotice] = useState(null);
  // Idempotência do PIX: mesma chave para a mesma tentativa; trava contra duplo clique
  const pixAttempt = useCheckoutAttemptKey();
  const pixInFlightRef = useRef(false);
  const [orderResult, setOrderResult] = useState(null);
  const [copiedPix, setCopiedPix] = useState(false);
  const [pixTimeLeft, setPixTimeLeft] = useState(900); // 15 minutos

  const numberInputRef = useRef(null);
  const { address: cepAddress, loading: loadingCep, error: cepError, fetchAddressByCep } = useViaCep();

  // Hook de Cartão de Crédito PCI-DSS
  const { 
    isReady: isMpReady, 
    isSubmitting: isSubmittingCard, 
    error: cardError, 
    processCardPayment,
    clearError: clearCardError 
  } = useMercadoPagoCard();

  // Configuração do react-hook-form com Zod
  const {
    register,
    handleSubmit,
    setValue,
    getValues,
    watch,
    trigger,
    formState: { errors }
  } = useForm({
    resolver: zodResolver(checkoutFormSchema),
    mode: 'onBlur',
    defaultValues: {
      fullName: '',
      email: '',
      cpf: '',
      phone: '',
      cep: '',
      street: '',
      number: '',
      complement: '',
      neighborhood: '',
      city: '',
      state: '',
      paymentMethod: 'pix',
      lgpdConsent: false
    }
  });

  const selectedPaymentMethod = watch('paymentMethod');
  const watchedCpf = watch('cpf');
  const watchedPhone = watch('phone');
  const watchedCep = watch('cep');

  // Monitoramento do pagamento PIX em tempo real
  const { isApproved } = usePaymentStatus({
    trackingToken: orderResult?.tracking_token || null,
    isPollingActive: currentStep === 4 && orderResult?.payment?.method === 'pix' && !orderResult?.paid,
    onPaymentApproved: () => {
      confetti({
        particleCount: 150,
        spread: 80,
        origin: { y: 0.6 },
        colors: ['#7C3AED', '#EC4899', '#10B981', '#F59E0B']
      });
      showToast('🎉 Pagamento PIX confirmado! Sua peça 3D já entrou na fila de manufatura.');
    }
  });

  // Fechamento com tecla Escape e trava de rolagem do body
  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape' && isCheckoutOpen && !isSubmittingPix && !isSubmittingCard) {
        setIsCheckoutOpen(false);
      }
    };

    if (isCheckoutOpen) {
      document.body.style.overflow = 'hidden';
      window.addEventListener('keydown', handleKeyDown);
    } else {
      document.body.style.overflow = 'unset';
    }

    return () => {
      document.body.style.overflow = 'unset';
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isCheckoutOpen, isSubmittingPix, isSubmittingCard, setIsCheckoutOpen]);

  // Cliente logado (M-26): nome e e-mail vêm da conta ao abrir o checkout, só nos campos vazios (continuam editáveis).
  useEffect(() => {
    if (!isCheckoutOpen || !user?.isLoggedIn) return;
    if (!getValues('fullName')) setValue('fullName', user.name);
    if (!getValues('email')) setValue('email', user.email);
  }, [isCheckoutOpen, user?.isLoggedIn, user?.name, user?.email, getValues, setValue]);

  // Contagem regressiva do PIX (15 minutos)
  useEffect(() => {
    let timer = null;
    if (currentStep === 4 && pixTimeLeft > 0 && !isApproved) {
      timer = setInterval(() => {
        setPixTimeLeft((prev) => (prev > 0 ? prev - 1 : 0));
      }, 1000);
    }
    return () => {
      if (timer) clearInterval(timer);
    };
  }, [currentStep, pixTimeLeft, isApproved]);

  // Atualização automática dos campos ao consultar CEP com foco no Número
  useEffect(() => {
    if (cepAddress) {
      setValue('street', cepAddress.street, { shouldValidate: true });
      setValue('neighborhood', cepAddress.neighborhood, { shouldValidate: true });
      setValue('city', cepAddress.city, { shouldValidate: true });
      setValue('state', cepAddress.state, { shouldValidate: true });

      setTimeout(() => {
        numberInputRef.current?.focus();
      }, 100);
    }
  }, [cepAddress, setValue]);

  if (!isCheckoutOpen) return null;

  // Espelho do cálculo do backend (só exibição): frete = cotação escolhida; desconto PIX só nas peças
  const shippingCostCents = selectedShipping?.price_in_cents ?? 0;
  const totals = calculateCartTotals(cart, 10, shippingCostCents);
  const isPixSelected = selectedPaymentMethod === 'pix';
  const totalToPayCents = isPixSelected ? totals.grandTotalPixCents : totals.grandTotalCartaoCents;

  // Handlers com máscaras progressivas
  const handleCpfChange = (e) => {
    const formatted = formatCPF(e.target.value);
    setValue('cpf', formatted, { shouldValidate: true });
  };

  const handlePhoneChange = (e) => {
    const formatted = formatPhone(e.target.value);
    setValue('phone', formatted, { shouldValidate: true });
  };

  const handleCepChange = (e) => {
    const formatted = formatCEP(e.target.value);
    setValue('cep', formatted, { shouldValidate: true });

    const clean = cleanNonDigits(formatted);
    if (clean.length === 8) {
      fetchAddressByCep(clean);
    }
  };

  // Frete recalculado no servidor divergiu do exibido (409): atualiza a opção e pede nova confirmação,
  // mantendo todos os dados já preenchidos no formulário.
  const applyShippingPriceChange = (err) => {
    const nextOption = err.shippingOptions.find((opt) => opt.service_code === err.shippingService);
    clearShippingCache();
    if (nextOption) {
      setSelectedShipping(normalizeShippingOption(nextOption));
    }
    setShippingNotice({ message: err.message });
    setCurrentStep(3);
  };

  const ensureShippingSelected = () => {
    if (!selectedShipping?.service_code) {
      setApiError('Calcule o frete e escolha uma opção de entrega na etapa 2 para continuar.');
      return false;
    }
    return true;
  };

  // Navegação entre etapas com validação Zod
  const handleNextStep = async () => {
    setApiError(null);
    clearCardError();

    if (currentStep === 1) {
      const isValid = await trigger(['fullName', 'email', 'cpf', 'phone']);
      if (isValid) setCurrentStep(2);
    } else if (currentStep === 2) {
      const isValid = await trigger(['cep', 'street', 'number', 'neighborhood', 'city', 'state']);
      if (isValid) setCurrentStep(3);
    }
  };

  const handlePrevStep = () => {
    setApiError(null);
    clearCardError();
    if (currentStep > 1) setCurrentStep((prev) => prev - 1);
  };

  // 1. Submissão do Pagamento via PIX (POST /api/checkout)
  const onSubmitPix = async (data) => {
    if (cart.length === 0) {
      showToast('Sua sacola está vazia!', 'error');
      setIsCheckoutOpen(false);
      return;
    }

    if (!ensureShippingSelected()) {
      return;
    }

    if (pixInFlightRef.current) {
      return;
    }
    pixInFlightRef.current = true;

    setIsSubmittingPix(true);
    setApiError(null);
    setShippingNotice(null);

    // Validador de UUID defensivo para variações de tamanho
    const isValidUuid = (val) =>
      typeof val === 'string' &&
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(val);

    // Payload consolidado estruturado (Submódulo F1.2 - Zero-Trust Client)
    const checkoutPayload = {
      customer: {
        name: data.fullName,
        email: data.email,
        cpf: data.cpf.replace(/\D/g, ''),
        phone: data.phone.replace(/\D/g, ''),
      },
      shipping_address: {
        cep: data.cep.replace(/\D/g, ''),
        street: data.street,
        number: data.number,
        complement: data.complement || null,
        neighborhood: data.neighborhood,
        city: data.city,
        state: data.state,
      },
      // O backend recalcula o frete pelo service_code; o valor exibido só serve para detectar divergência (409)
      shipping_service: selectedShipping.service_code,
      shipping_cost_cents: selectedShipping.price_in_cents,
      payment_method: 'pix', // 'pix' ou 'credit_card'
      card_token: null, // Vindo do SDK do Mercado Pago
      installments: 1,
      items: cart.map((item) => {
        const rawSizeId = item.size?.id || item.selectedSize?.id || item.sizeId || null;
        return {
          product_id: item.productId || item.product?.id || item.id,
          color_id: item.color?.id || item.selectedColor?.id || item.colorId || item.color_id,
          size_id: isValidUuid(rawSizeId) ? rawSizeId : null,
          material: item.material?.id || item.selectedMaterial?.id || 'pla', // 'pla', 'petg', etc.
          quantity: item.quantity,
        };
      }),
      // Nós legados complementares para compatibilidade com rotas de API existentes
      shipping: {
        zip_code: data.cep.replace(/\D/g, ''),
        street: data.street,
        number: data.number,
        complement: data.complement || null,
        neighborhood: data.neighborhood,
        city: data.city,
        state: data.state,
        service_name: selectedShipping.service_name,
      },
      payment: {
        payment_method: 'pix',
      },
    };

    try {
      const { response, result } = await postCheckout(
        `${API_BASE}/checkout`,
        checkoutPayload,
        pixAttempt.keyFor(checkoutPayload)
      );

      // Resultado definitivo: a próxima tentativa de compra usa uma chave nova
      pixAttempt.rotate();

      if (isShippingPriceChanged(response.status, result)) {
        throw new ShippingPriceChangedError(result);
      }

      if (!response.ok) {
        throw new Error(result.message || 'Falha ao processar o checkout.');
      }

      setOrderResult(result);
      setCurrentStep(4);
      clearCart();

      confetti({
        particleCount: 120,
        spread: 70,
        origin: { y: 0.6 },
        colors: ['#7C3AED', '#FF85B2', '#FBBF24', '#34D399']
      });

      showToast(`✨ Encomenda #${result.order_number} gerada com sucesso!`);

    } catch (err) {
      if (err instanceof ShippingPriceChangedError) {
        applyShippingPriceChange(err);
      } else {
        setApiError(err.message || 'Ocorreu um erro ao comunicar com o servidor de pagamento.');
      }
    } finally {
      pixInFlightRef.current = false;
      setIsSubmittingPix(false);
    }
  };

  // 2. Submissão do Pagamento via Cartão de Crédito (Mercado Pago Token)
  const handleCardSubmit = async (cardFormData) => {
    const isValid = await trigger();
    if (!isValid) {
      showToast('Por favor, revise os dados das etapas anteriores.', 'error');
      return;
    }

    if (!ensureShippingSelected()) {
      return;
    }

    setShippingNotice(null);

    try {
      const result = await processCardPayment(
        cardFormData,
        getValues(),
        cart,
        shippingCostCents,
        selectedShipping
      );

      clearCart();

      // Pagamento recebido, mas em conferência manual: mostra o aviso e o rastreio (sem retry nem volta ao pagamento)
      if (result.code === PAYMENT_UNDER_REVIEW) {
        setOrderResult(result);
        setCurrentStep(4);
        return;
      }

      const trackingToken = result.tracking_token;
      const isApprovedStatus = result.payment?.status === 'approved' || result.paid;
      const isInProcess = result.payment?.status === 'in_process' || result.payment?.status === 'pending';

      if (isApprovedStatus) {
        confetti({
          particleCount: 150,
          spread: 80,
          origin: { y: 0.6 },
          colors: ['#7C3AED', '#EC4899', '#10B981', '#F59E0B']
        });

        showToast(`🎉 Pagamento aprovado! Encomenda #${result.order_number} confirmada.`);

        // Redirecionamento suave para a tela de rastreamento
        setTimeout(() => {
          setIsCheckoutOpen(false);
          window.history.pushState({}, '', `/?token=${trackingToken}&success=true`);
          setActiveTab('tracking');
        }, 1200);

      } else if (isInProcess) {
        showToast('⚠️ Pagamento em análise pelo banco. Acompanhe o status em tempo real.', 'info');
        
        setTimeout(() => {
          setIsCheckoutOpen(false);
          window.history.pushState({}, '', `/?token=${trackingToken}&status=pending`);
          setActiveTab('tracking');
        }, 1200);
      } else {
        setOrderResult(result);
        setCurrentStep(4);
      }

    } catch (err) {
      if (err instanceof ShippingPriceChangedError) {
        applyShippingPriceChange(err);
      } else if (err instanceof CheckoutInProgressError) {
        setApiError(err.message);
      }
      // Demais erros são exibidos dentro do CreditCardPaymentStep
    }
  };

  const handleCopyPix = () => {
    const pixCode = orderResult?.payment?.qr_code;
    if (pixCode) {
      navigator.clipboard.writeText(pixCode);
      setCopiedPix(true);
      showToast('📋 Código PIX Copia e Cola copiado com sucesso!');
      setTimeout(() => setCopiedPix(false), 3000);
    }
  };

  const formatSeconds = (sec) => {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-3 sm:p-4 md:p-6">
      
      {/* Backdrop */}
      <div 
        onClick={() => !isSubmittingPix && !isSubmittingCard && setIsCheckoutOpen(false)}
        className="fixed inset-0 bg-mimoo-dark/60 backdrop-blur-md transition-opacity animate-in fade-in duration-300"
      />

      {/* Container do Modal */}
      <div className="relative w-full max-w-2xl bg-white border-2 border-mimoo-dark rounded-3xl shadow-2xl overflow-hidden z-10 animate-in zoom-in-95 duration-200 flex flex-col max-h-[92vh]">
        
        {/* Header do Modal */}
        <div className="p-5 sm:p-6 border-b-2 border-mimoo-dark/15 bg-gradient-to-r from-purple-50 via-pink-50 to-amber-50 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-11 h-11 rounded-2xl bg-mimoo-purple text-white border-2 border-mimoo-dark shadow-chunky-sm flex items-center justify-center font-bold">
              <Sparkles className="w-6 h-6 text-mimoo-yellow" />
            </div>
            <div>
              <h2 className="font-fredoka font-bold text-xl sm:text-2xl text-mimoo-dark leading-tight">
                Finalizar Encomenda 3D
              </h2>
              <p className="text-xs font-nunito text-slate-500 font-semibold">
                MIMOO LAB • Extrusão Personalizada Sob Demanda
              </p>
            </div>
          </div>

          <button
            onClick={() => !isSubmittingPix && !isSubmittingCard && setIsCheckoutOpen(false)}
            disabled={isSubmittingPix || isSubmittingCard}
            className="p-2.5 rounded-2xl border-2 border-mimoo-dark bg-white shadow-chunky-sm hover:bg-pink-50 transition-all text-mimoo-dark disabled:opacity-50"
            title="Fechar (Esc)"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Stepper Progress Indicator (Etapas 1, 2, 3) */}
        {currentStep < 4 && (
          <div className="bg-slate-50 px-6 py-3.5 border-b border-slate-200">
            <div className="flex items-center justify-between max-w-md mx-auto relative">
              <div className="absolute top-1/2 left-0 right-0 h-1 bg-slate-200 -translate-y-1/2 z-0" />
              <div 
                className="absolute top-1/2 left-0 h-1 bg-mimoo-purple -translate-y-1/2 z-0 transition-all duration-300"
                style={{ width: currentStep === 1 ? '0%' : currentStep === 2 ? '50%' : '100%' }}
              />

              {/* Passo 1 */}
              <div className="relative z-10 flex flex-col items-center gap-1">
                <div className={`w-8 h-8 rounded-xl border-2 flex items-center justify-center font-bold text-xs shadow-chunky-sm ${
                  currentStep >= 1 ? 'bg-mimoo-purple text-white border-mimoo-dark' : 'bg-white text-slate-400 border-slate-300'
                }`}>
                  <User className="w-4 h-4" />
                </div>
                <span className="text-[11px] font-fredoka font-semibold text-slate-700">Dados</span>
              </div>

              {/* Passo 2 */}
              <div className="relative z-10 flex flex-col items-center gap-1">
                <div className={`w-8 h-8 rounded-xl border-2 flex items-center justify-center font-bold text-xs shadow-chunky-sm ${
                  currentStep >= 2 ? 'bg-mimoo-purple text-white border-mimoo-dark' : 'bg-white text-slate-400 border-slate-300'
                }`}>
                  <Truck className="w-4 h-4" />
                </div>
                <span className="text-[11px] font-fredoka font-semibold text-slate-700">Entrega</span>
              </div>

              {/* Passo 3 */}
              <div className="relative z-10 flex flex-col items-center gap-1">
                <div className={`w-8 h-8 rounded-xl border-2 flex items-center justify-center font-bold text-xs shadow-chunky-sm ${
                  currentStep >= 3 ? 'bg-mimoo-purple text-white border-mimoo-dark' : 'bg-white text-slate-400 border-slate-300'
                }`}>
                  <CreditCard className="w-4 h-4" />
                </div>
                <span className="text-[11px] font-fredoka font-semibold text-slate-700">Pagamento</span>
              </div>
            </div>
          </div>
        )}

        {/* Corpo do Formulário */}
        <div className="flex-1 overflow-y-auto p-5 sm:p-7 space-y-6">
          
          {shippingNotice && (
            <div
              role="alert"
              aria-live="assertive"
              className="p-4 rounded-3xl bg-mimoo-yellow-light border-2 border-mimoo-dark shadow-chunky-sm text-mimoo-dark font-nunito text-sm flex items-start gap-3"
            >
              <Truck className="w-5 h-5 flex-shrink-0 text-mimoo-purple mt-0.5" aria-hidden="true" />
              <div className="space-y-1">
                <p className="font-fredoka font-bold text-base">Muuu! O valor do frete mudou</p>
                <p className="font-semibold">{shippingNotice.message}</p>
                <p className="text-xs">
                  Seus dados continuam preenchidos. Confira o novo total no resumo e toque em pagar de novo para confirmar.
                </p>
              </div>
            </div>
          )}

          {apiError && (
            <div className="p-4 rounded-2xl bg-red-50 border-2 border-red-300 text-red-700 text-xs font-semibold flex items-center gap-3">
              <AlertCircle className="w-5 h-5 flex-shrink-0 text-red-500" />
              <span>{apiError}</span>
            </div>
          )}

          <form id="checkout-form" onSubmit={handleSubmit(onSubmitPix)} className="space-y-5">
            
            {/* ETAPA 1: DADOS PESSOAIS */}
            {currentStep === 1 && (
              <div className="space-y-4 animate-in fade-in duration-200">
                <div className="border-b border-slate-100 pb-2">
                  <h3 className="font-fredoka font-bold text-base text-mimoo-dark">
                    1. Quem vai receber a criação 3D?
                  </h3>
                  <p className="text-xs text-slate-500 font-nunito">
                    Usaremos esses dados para notificar o andamento da extrusão e emitir o rastreio.
                  </p>
                </div>

                <div className="space-y-3 font-nunito">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Nome Completo *
                    </label>
                    <input
                      type="text"
                      placeholder="Ex: Ana Clara Silva"
                      {...register('fullName')}
                      className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                        errors.fullName ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                      }`}
                    />
                    {errors.fullName && (
                      <p className="text-xs text-red-500 mt-1 font-semibold">{errors.fullName.message}</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      E-mail para Rastreio *
                    </label>
                    <input
                      type="email"
                      placeholder="seu.email@exemplo.com"
                      {...register('email')}
                      className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                        errors.email ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                      }`}
                    />
                    {errors.email && (
                      <p className="text-xs text-red-500 mt-1 font-semibold">{errors.email.message}</p>
                    )}
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        CPF do Titular *
                      </label>
                      <input
                        type="text"
                        placeholder="000.000.000-00"
                        maxLength={14}
                        value={watchedCpf || ''}
                        onChange={handleCpfChange}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                          errors.cpf ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.cpf && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.cpf.message}</p>
                      )}
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        WhatsApp / Celular *
                      </label>
                      <input
                        type="text"
                        placeholder="(11) 99999-9999"
                        maxLength={15}
                        value={watchedPhone || ''}
                        onChange={handlePhoneChange}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                          errors.phone ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.phone && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.phone.message}</p>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* ETAPA 2: ENDEREÇO DE ENTREGA */}
            {currentStep === 2 && (
              <div className="space-y-4 animate-in fade-in duration-200">
                <div className="border-b border-slate-100 pb-2">
                  <h3 className="font-fredoka font-bold text-base text-mimoo-dark">
                    2. Onde devemos entregar sua encomenda?
                  </h3>
                  <p className="text-xs text-slate-500 font-nunito">
                    Digite seu CEP para preenchimento automático do endereço.
                  </p>
                </div>

                <div className="space-y-3 font-nunito">
                  <div className="max-w-xs">
                    <label className="block text-xs font-bold text-slate-700 mb-1 flex items-center justify-between">
                      <span>CEP *</span>
                      {loadingCep && (
                        <span className="text-mimoo-purple text-[11px] flex items-center gap-1 font-semibold">
                          <Loader2 className="w-3 h-3 animate-spin" /> Buscando Correios...
                        </span>
                      )}
                    </label>
                    <input
                      type="text"
                      placeholder="00000-000"
                      maxLength={9}
                      value={watchedCep || ''}
                      onChange={handleCepChange}
                      className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                        errors.cep || cepError ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                      }`}
                    />
                    {(errors.cep || cepError) && (
                      <p className="text-xs text-red-500 mt-1 font-semibold">{errors.cep?.message || cepError}</p>
                    )}
                  </div>

                  <div className="grid grid-cols-3 gap-3">
                    <div className="col-span-2">
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        Rua / Logradouro *
                      </label>
                      <input
                        type="text"
                        placeholder="Ex: Rua das Flores"
                        {...register('street')}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                          errors.street ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.street && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.street.message}</p>
                      )}
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        Número *
                      </label>
                      <input
                        ref={numberInputRef}
                        type="text"
                        placeholder="123"
                        {...register('number')}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                          errors.number ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.number && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.number.message}</p>
                      )}
                    </div>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        Complemento (Opcional)
                      </label>
                      <input
                        type="text"
                        placeholder="Apto 42, Bloco B"
                        {...register('complement')}
                        className="w-full px-3.5 py-2.5 rounded-xl border-2 border-slate-300 bg-white text-sm outline-none focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        Bairro *
                      </label>
                      <input
                        type="text"
                        placeholder="Centro"
                        {...register('neighborhood')}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                          errors.neighborhood ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.neighborhood && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.neighborhood.message}</p>
                      )}
                    </div>
                  </div>

                  <div className="grid grid-cols-3 gap-3">
                    <div className="col-span-2">
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        Cidade *
                      </label>
                      <input
                        type="text"
                        placeholder="São Paulo"
                        {...register('city')}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm outline-none transition-all ${
                          errors.city ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.city && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.city.message}</p>
                      )}
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        UF *
                      </label>
                      <input
                        type="text"
                        placeholder="SP"
                        maxLength={2}
                        {...register('state')}
                        className={`w-full px-3.5 py-2.5 rounded-xl border-2 bg-white text-sm uppercase outline-none transition-all ${
                          errors.state ? 'border-red-400 bg-red-50/50' : 'border-slate-300 focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20'
                        }`}
                      />
                      {errors.state && (
                        <p className="text-xs text-red-500 mt-1 font-semibold">{errors.state.message}</p>
                      )}
                    </div>
                  </div>

                  {/* Seleção de Opção de Envio (PAC / SEDEX) com SLA Desmembrado */}
                  <div className="pt-3 border-t border-slate-100">
                    <ShippingSelector
                      items={cart}
                      onShippingSelected={setSelectedShipping}
                      selectedOption={selectedShipping}
                      initialCep={watchedCep}
                    />
                  </div>
                </div>
              </div>
            )}

            {/* ETAPA 3: FORMA DE PAGAMENTO & REVISÃO */}
            {currentStep === 3 && (
              <div className="space-y-5 animate-in fade-in duration-200">
                <div className="border-b border-slate-100 pb-2">
                  <h3 className="font-fredoka font-bold text-base text-mimoo-dark flex items-center gap-2">
                    <span>3. Escolha como deseja pagar</span>
                  </h3>
                  <p className="text-xs text-slate-500 font-nunito">
                    Pagamento processado com segurança de ponta pelo Mercado Pago.
                  </p>
                </div>

                {/* Seletor de Método de Pagamento */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 font-fredoka">
                  
                  {/* Opção PIX */}
                  <label 
                    className={`relative p-4 rounded-2xl border-2 cursor-pointer transition-all flex flex-col justify-between shadow-chunky-sm ${
                      selectedPaymentMethod === 'pix' 
                        ? 'border-mimoo-purple bg-purple-50/80 ring-2 ring-mimoo-purple/30' 
                        : 'border-slate-200 bg-white hover:bg-slate-50'
                    }`}
                  >
                    <input
                      type="radio"
                      value="pix"
                      {...register('paymentMethod')}
                      className="sr-only"
                    />
                    <div className="flex items-center justify-between mb-2">
                      <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-lg bg-emerald-500 text-white flex items-center justify-center font-bold">
                          <QrCode className="w-4 h-4" />
                        </div>
                        <span className="font-bold text-sm text-mimoo-dark">PIX Instantâneo</span>
                      </div>
                      <span className="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-bold">
                        ⚡ Aprovação Imediata
                      </span>
                    </div>
                    <p className="text-xs font-nunito text-slate-500 leading-snug">
                      QR Code gerado na hora com Copia e Cola. Sua peça entra imediatamente na fila de impressão.
                    </p>
                  </label>

                  {/* Opção Cartão de Crédito */}
                  <label 
                    className={`relative p-4 rounded-2xl border-2 cursor-pointer transition-all flex flex-col justify-between shadow-chunky-sm ${
                      selectedPaymentMethod === 'credit_card' 
                        ? 'border-mimoo-purple bg-purple-50/80 ring-2 ring-mimoo-purple/30' 
                        : 'border-slate-200 bg-white hover:bg-slate-50'
                    }`}
                  >
                    <input
                      type="radio"
                      value="credit_card"
                      {...register('paymentMethod')}
                      className="sr-only"
                    />
                    <div className="flex items-center justify-between mb-2">
                      <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-lg bg-mimoo-purple text-white flex items-center justify-center font-bold">
                          <CreditCard className="w-4 h-4" />
                        </div>
                        <span className="font-bold text-sm text-mimoo-dark">Cartão de Crédito</span>
                      </div>
                      <span className="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 text-[10px] font-bold">
                        Até 6x
                      </span>
                    </div>
                    <p className="text-xs font-nunito text-slate-500 leading-snug">
                      Parcelamento facilitado via token seguro e blindado do Mercado Pago.
                    </p>
                  </label>
                </div>

                {/* Callout Lúdico de Impressão 3D */}
                <div className="rounded-2xl border-2 border-amber-300 bg-gradient-to-r from-amber-50 to-yellow-50 p-3.5 flex items-start gap-3 shadow-chunky-sm">
                  <Clock className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
                  <div className="text-xs font-nunito leading-relaxed">
                    <strong className="block font-fredoka text-amber-900 font-bold">
                      Peças Fabricadas Sob Encomenda
                    </strong>
                    <span className="text-amber-800">
                      Tempo estimado de extrusão das suas peças: <strong>~{cartTotalPrintHours} horas</strong> na fazenda 3D. 
                      Assim que o pagamento for concluído, a máquina começará o pré-aquecimento!
                    </span>
                  </div>
                </div>

                {/* Resumo Financeiro */}
                <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200 space-y-2 text-xs font-fredoka">
                  <div className="flex justify-between text-slate-600">
                    <span>Subtotal das Criações ({cart.length} itens):</span>
                    <span>{formatMoney(totals.subtotalCartaoCents)}</span>
                  </div>
                  {isPixSelected && totals.savingsCents > 0 && (
                    <div className="flex justify-between text-emerald-600 font-bold">
                      <span>Desconto PIX (10% nas peças):</span>
                      <span>- {formatMoney(totals.savingsCents)}</span>
                    </div>
                  )}
                  <div className="flex justify-between text-slate-600">
                    <span>Frete:</span>
                    <span>
                      {selectedShipping
                        ? formatMoney(shippingCostCents)
                        : <span className="text-slate-600 font-nunito text-[11px]">Volte à etapa 2 para calcular</span>}
                    </span>
                  </div>
                  <div className="flex justify-between text-base font-bold text-mimoo-dark pt-2 border-t border-slate-200">
                    <span>{selectedShipping ? 'Total a Pagar:' : 'Total sem frete:'}</span>
                    <span className="text-mimoo-purple text-lg">
                      {formatMoney(totalToPayCents)}
                    </span>
                  </div>
                </div>

                {/* Checkbox de Consentimento LGPD */}
                <div className="pt-2">
                  <label className="flex items-start gap-3 cursor-pointer select-none">
                    <input
                      type="checkbox"
                      {...register('lgpdConsent')}
                      className="w-4 h-4 mt-0.5 rounded border-2 border-slate-400 text-mimoo-purple focus:ring-mimoo-purple"
                    />
                    <span className="text-xs text-slate-600 font-nunito leading-snug">
                      Concordo com os <strong>termos de produção personalizada sob demanda</strong> e autorizo o processamento dos meus dados para emissão e entrega da encomenda (em conformidade com a LGPD).
                    </span>
                  </label>
                  {errors.lgpdConsent && (
                    <p className="text-xs text-red-500 mt-1 font-semibold">{errors.lgpdConsent.message}</p>
                  )}
                </div>

                {/* Formulário Seguro de Cartão de Crédito (Mercado Pago Brick) */}
                {selectedPaymentMethod === 'credit_card' && (
                  <div className="pt-4 border-t border-slate-200">
                    <CreditCardPaymentStep
                      totalCents={totals.grandTotalCartaoCents}
                      customerData={watch()}
                      onSubmitCard={handleCardSubmit}
                      isSubmitting={isSubmittingCard}
                      errorMessage={cardError}
                    />
                  </div>
                )}
              </div>
            )}

            {/* ETAPA 4: TELA DE PAGAMENTO PIX / CONFIRMAÇÃO EM TEMPO REAL */}
            {currentStep === 4 && orderResult && (
              <div className="space-y-6 text-center py-2 animate-in zoom-in-95 duration-300">
                
                {orderResult.code === PAYMENT_UNDER_REVIEW ? (
                  /* Pagamento recebido com divergência: pedido em revisão manual */
                  <div className="space-y-4 py-4" role="status">
                    <div className="w-16 h-16 rounded-full bg-amber-100 border-2 border-amber-500 text-amber-700 flex items-center justify-center mx-auto shadow-chunky">
                      <AlertCircle className="w-10 h-10" aria-hidden="true" />
                    </div>
                    <div className="space-y-1">
                      <h3 className="font-fredoka font-bold text-2xl text-mimoo-dark">
                        Pagamento em conferência
                      </h3>
                      <p className="text-sm font-nunito text-slate-600 max-w-md mx-auto">
                        {orderResult.message}
                      </p>
                    </div>

                    <div className="p-4 rounded-2xl bg-amber-50 border-2 border-mimoo-dark max-w-md mx-auto text-left text-xs font-nunito space-y-1.5 shadow-chunky-sm">
                      <div className="flex justify-between font-fredoka">
                        <span className="text-slate-500">Código da Encomenda:</span>
                        <strong className="text-mimoo-purple">{orderResult.order_number}</strong>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => {
                        setIsCheckoutOpen(false);
                        window.history.pushState({}, '', `/?token=${orderResult.tracking_token}&status=pending`);
                        setActiveTab('tracking');
                      }}
                      className="btn-chunky-purple py-3.5 px-8 text-sm font-bold shadow-chunky inline-flex items-center gap-2"
                    >
                      <Layers className="w-4 h-4" aria-hidden="true" />
                      <span>Acompanhar meu pedido</span>
                    </button>
                  </div>
                ) : isApproved ? (
                  /* Tela de Confirmação de Sucesso */
                  <div className="space-y-4 py-4">
                    <div className="w-16 h-16 rounded-full bg-emerald-100 border-2 border-emerald-500 text-emerald-600 flex items-center justify-center mx-auto shadow-chunky">
                      <CheckCircle2 className="w-10 h-10" />
                    </div>
                    <div className="space-y-1">
                      <h3 className="font-fredoka font-bold text-2xl text-mimoo-dark">
                        Pagamento Aprovado com Sucesso! 🎉
                      </h3>
                      <p className="text-sm font-nunito text-slate-600">
                        Sua encomenda <strong>#{orderResult.order_number}</strong> já foi enviada para a fila das impressoras 3D.
                      </p>
                    </div>

                    <div className="p-4 rounded-2xl bg-purple-50 border-2 border-mimoo-dark max-w-md mx-auto text-left text-xs font-nunito space-y-1.5 shadow-chunky-sm">
                      <div className="flex justify-between font-fredoka">
                        <span className="text-slate-500">Código da Encomenda:</span>
                        <strong className="text-mimoo-purple">{orderResult.order_number}</strong>
                      </div>
                      <div className="flex justify-between font-fredoka">
                        <span className="text-slate-500">Status Fabril:</span>
                        <strong className="text-emerald-700 font-bold">🟣 Na Fila de Impressão 3D</strong>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => {
                        setIsCheckoutOpen(false);
                        window.history.pushState({}, '', `/?token=${orderResult.tracking_token}&success=true`);
                        setActiveTab('tracking');
                      }}
                      className="btn-chunky-purple py-3.5 px-8 text-sm font-bold shadow-chunky inline-flex items-center gap-2"
                    >
                      <Layers className="w-4 h-4" />
                      <span>Acompanhar Produção em Tempo Real</span>
                    </button>
                  </div>
                ) : (
                  /* Tela de Pagamento PIX com QR Code */
                  <div className="space-y-5">
                    <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-amber-100 text-amber-900 border border-amber-300 text-xs font-fredoka font-bold">
                      <Clock className="w-3.5 h-3.5 animate-pulse text-amber-700" />
                      <span>Aguardando Pagamento • Expira em {formatSeconds(pixTimeLeft)}</span>
                    </div>

                    <div className="space-y-1">
                      <h3 className="font-fredoka font-bold text-xl text-mimoo-dark">
                        Pague com PIX para Iniciar a Impressão
                      </h3>
                      <p className="text-xs text-slate-500 font-nunito">
                        Abra o app do seu banco, escolha <strong>Pagar com PIX</strong> e escaneie o QR Code abaixo:
                      </p>
                    </div>

                    {/* QR Code */}
                    <div className="p-4 bg-white rounded-2xl border-2 border-mimoo-dark inline-block shadow-chunky">
                      {orderResult.payment?.qr_code_base64 ? (
                        <img 
                          src={`data:image/png;base64,${orderResult.payment.qr_code_base64}`} 
                          alt="QR Code PIX" 
                          className="w-48 h-48 sm:w-56 sm:h-56 mx-auto object-contain"
                        />
                      ) : (
                        <div className="w-48 h-48 bg-slate-100 flex items-center justify-center text-xs text-slate-400 font-nunito">
                          <QrCode className="w-16 h-16 text-slate-300" />
                        </div>
                      )}
                    </div>

                    {/* Copia e Cola */}
                    <div className="max-w-md mx-auto space-y-2">
                      <label className="block text-xs font-bold text-slate-600 text-left">
                        Ou copie o código PIX Copia e Cola:
                      </label>
                      <div className="flex items-center gap-2">
                        <input
                          type="text"
                          readOnly
                          value={orderResult.payment?.qr_code || ''}
                          className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 text-xs font-mono text-slate-700 select-all"
                        />
                        <button
                          type="button"
                          onClick={handleCopyPix}
                          className="btn-chunky-pink py-2 px-3 text-xs font-bold whitespace-nowrap flex items-center gap-1.5"
                        >
                          {copiedPix ? <Check className="w-4 h-4 text-emerald-700" /> : <Copy className="w-4 h-4" />}
                          <span>{copiedPix ? 'Copiado!' : 'Copiar'}</span>
                        </button>
                      </div>
                    </div>

                    <div className="flex items-center justify-center gap-2 text-xs text-slate-500 font-nunito pt-2">
                      <Loader2 className="w-4 h-4 text-mimoo-purple animate-spin" />
                      <span>Identificando pagamento em tempo real...</span>
                    </div>
                  </div>
                )}
              </div>
            )}

          </form>
        </div>

        {/* Footer Controls (Etapas 1, 2, 3) */}
        {currentStep < 4 && (
          <div className="p-4 sm:p-5 border-t-2 border-mimoo-dark/15 bg-slate-50 flex items-center justify-between">
            {currentStep > 1 ? (
              <button
                type="button"
                onClick={handlePrevStep}
                disabled={isSubmittingPix || isSubmittingCard}
                className="px-4 py-2.5 rounded-2xl border-2 border-mimoo-dark bg-white font-fredoka font-bold text-xs text-mimoo-dark shadow-chunky-sm hover:bg-slate-100 transition-all flex items-center gap-1.5 disabled:opacity-50"
              >
                <ArrowLeft className="w-4 h-4" />
                <span>Voltar</span>
              </button>
            ) : (
              <div />
            )}

            {currentStep < 3 ? (
              <button
                type="button"
                onClick={handleNextStep}
                className="btn-chunky-purple py-2.5 px-6 font-fredoka font-bold text-xs flex items-center gap-1.5 shadow-chunky"
              >
                <span>Avançar</span>
                <ArrowRight className="w-4 h-4" />
              </button>
            ) : selectedPaymentMethod === 'pix' ? (
              <button
                type="submit"
                form="checkout-form"
                disabled={isSubmittingPix}
                className="btn-chunky-purple py-3 px-7 font-fredoka font-bold text-sm flex items-center gap-2 shadow-chunky disabled:opacity-50"
              >
                {isSubmittingPix ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin text-white" />
                    <span>Processando Encomenda...</span>
                  </>
                ) : (
                  <>
                    <Sparkles className="w-4 h-4 text-mimoo-yellow" />
                    <span>Gerar PIX de Pagamento</span>
                    <ArrowRight className="w-4 h-4" />
                  </>
                )}
              </button>
            ) : (
              <span className="text-xs font-nunito font-semibold text-slate-500 flex items-center gap-1.5">
                <ShieldCheck className="w-4 h-4 text-emerald-600" />
                <span>Clique no botão seguro do cartão acima</span>
              </span>
            )}
          </div>
        )}

      </div>
    </div>
  );
};
