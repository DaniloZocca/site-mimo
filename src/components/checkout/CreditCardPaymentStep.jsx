import React, { useState } from 'react';
import { CardPayment } from '@mercadopago/sdk-react';
import { 
  ShieldCheck, 
  Lock, 
  CreditCard, 
  AlertCircle, 
  Loader2, 
  Sparkles,
  Info
} from 'lucide-react';
import { cleanNonDigits } from '../../utils/validators';

/**
 * Componente Visual do Formulário de Cartão de Crédito (Card Payment Brick)
 * Totalmente compatível com PCI-DSS SAQ A e integrado à identidade MIMOO LAB.
 *
 * @param {Object} props
 * @param {number} props.totalCents - Valor total da encomenda em centavos
 * @param {Object} props.customerData - Dados do comprador (email, cpf, nome)
 * @param {Function} props.onSubmitCard - Callback que recebe os dados tokenizados do Brick
 * @param {boolean} [props.isSubmitting] - Estado de carregamento do envio
 * @param {string|null} [props.errorMessage] - Mensagem de erro de autorização/recusa
 */
export const CreditCardPaymentStep = ({
  totalCents = 0,
  customerData = {},
  onSubmitCard,
  isSubmitting = false,
  errorMessage = null,
}) => {
  const [isBrickReady, setIsBrickReady] = useState(false);
  const [sdkRenderError, setSdkRenderError] = useState(null);

  const amountInReais = Number((totalCents / 100).toFixed(2));
  const cleanCpf = cleanNonDigits(customerData?.cpf || '');

  // Configurações de Inicialização do Card Payment Brick
  const initialization = {
    amount: amountInReais > 0 ? amountInReais : 1.0,
    payer: {
      email: customerData?.email || '',
      identification: {
        type: 'CPF',
        number: cleanCpf,
      },
    },
  };

  // Customizações Visuais com a Paleta MIMOO LAB
  const customization = {
    paymentMethods: {
      maxInstallments: 6,
      minInstallments: 1,
    },
    visual: {
      hideFormTitle: true,
      style: {
        theme: 'default',
        customVariables: {
          formBackgroundColor: '#FFFFFF',
          baseColor: '#7C3AED',
          inputBorderRadius: '16px',
          buttonBorderRadius: '16px',
          baseColorFirstVariant: '#6D28D9',
          baseColorSecondVariant: '#F472B6',
          fontFamily: 'Poppins, Fredoka, Nunito, sans-serif',
          fontSize: '14px',
        },
      },
    },
    texts: {
      cardholderName: {
        label: 'Nome impresso no cartão',
        placeholder: 'Como aparece no seu cartão',
      },
      cardNumber: {
        label: 'Número do Cartão de Crédito',
        placeholder: '0000 0000 0000 0000',
      },
      expirationDate: {
        label: 'Validade (MM/AA)',
      },
      securityCode: {
        label: 'CVV (Código de Segurança)',
      },
      installments: {
        label: 'Número de Parcelas',
      },
      formSubmit: 'Pagar e Iniciar Produção 3D ✨',
    },
  };

  const handleCardSubmit = async (formData) => {
    setSdkRenderError(null);
    if (typeof onSubmitCard === 'function') {
      await onSubmitCard(formData);
    }
  };

  const handleBrickError = (error) => {
    console.error('Erro interno do Mercado Pago Brick:', error);
    setSdkRenderError('Não foi possível carregar o formulário do cartão. Verifique sua conexão.');
  };

  const handleBrickReady = () => {
    setIsBrickReady(true);
  };

  return (
    <div className="w-full space-y-4 animate-in fade-in duration-300">
      
      {/* Banner de Segurança PCI-DSS */}
      <div className="p-3.5 rounded-2xl bg-gradient-to-r from-purple-50 to-pink-50 border-2 border-mimoo-dark/15 flex items-start gap-3 shadow-chunky-sm">
        <div className="w-8 h-8 rounded-xl bg-mimoo-purple text-white flex items-center justify-center flex-shrink-0 font-bold mt-0.5">
          <Lock className="w-4 h-4 text-mimoo-yellow" />
        </div>
        <div className="text-xs font-nunito leading-relaxed">
          <strong className="font-fredoka text-mimoo-dark font-bold block flex items-center gap-1.5">
            <span>Ambiente 100% Blindado (PCI-DSS)</span>
            <ShieldCheck className="w-4 h-4 text-emerald-600 inline" />
          </strong>
          <span className="text-slate-600">
            Seus dados são criptografados de ponta a ponta diretamente pelo Mercado Pago. Nenhum dado do seu cartão é armazenado em nossos servidores.
          </span>
        </div>
      </div>

      {/* Alertas de Recusa ou Falha */}
      {(errorMessage || sdkRenderError) && (
        <div className="p-4 rounded-2xl bg-pink-50 border-2 border-pink-300 text-pink-900 text-xs font-nunito flex items-start gap-3 animate-in shake">
          <AlertCircle className="w-5 h-5 flex-shrink-0 text-pink-600 mt-0.5" />
          <div className="space-y-0.5">
            <strong className="block font-fredoka font-bold text-sm">
              Não foi possível autorizar o cartão
            </strong>
            <p className="leading-snug">{errorMessage || sdkRenderError}</p>
          </div>
        </div>
      )}

      {/* Skeleton de Carregamento enquanto o Brick monta os Iframes */}
      {!isBrickReady && (
        <div className="p-8 rounded-2xl bg-slate-50 border border-slate-200 text-center space-y-3 animate-pulse">
          <Loader2 className="w-8 h-8 text-mimoo-purple animate-spin mx-auto" />
          <div className="space-y-1">
            <span className="font-fredoka text-xs font-bold text-slate-700 block">
              Preparando ambiente seguro de pagamento...
            </span>
            <span className="text-[11px] font-nunito text-slate-400">
              Carregando módulos criptografados do Mercado Pago
            </span>
          </div>
        </div>
      )}

      {/* Container do Brick Oficial com estilização MIMOO LAB */}
      <div
        className={`relative transition-opacity duration-300 ${isBrickReady ? 'opacity-100' : 'opacity-0 h-0 overflow-hidden'}`}
        aria-busy={isSubmitting}
      >
        <CardPayment
          initialization={initialization}
          customization={customization}
          onSubmit={handleCardSubmit}
          onReady={handleBrickReady}
          onError={handleBrickError}
        />

        {/* Bloqueia novos cliques enquanto o pagamento está sendo processado (evita cobrança em dobro) */}
        {isSubmitting && (
          <div
            role="status"
            aria-live="polite"
            className="absolute inset-0 z-10 rounded-3xl bg-white/80 border-2 border-mimoo-dark shadow-chunky-sm flex flex-col items-center justify-center gap-2 text-center p-4"
          >
            <Loader2 className="w-8 h-8 text-mimoo-purple animate-spin" aria-hidden="true" />
            <span className="font-fredoka font-bold text-sm text-mimoo-dark">Processando seu pagamento...</span>
            <span className="font-nunito text-xs text-slate-600">Não feche nem atualize a página.</span>
          </div>
        )}
      </div>

      {/* Dica de Parcelamento */}
      <div className="flex items-center justify-between text-[11px] text-slate-400 font-nunito pt-1 px-1">
        <span className="flex items-center gap-1">
          <CreditCard className="w-3.5 h-3.5 text-mimoo-purple" />
          <span>Bandeiras: Visa, Mastercard, Elo, Hipercard, Amex</span>
        </span>
        <span className="font-semibold text-emerald-600">
          Parcelamento em até 6x
        </span>
      </div>

    </div>
  );
};
