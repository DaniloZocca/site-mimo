import React, { useState } from 'react';
import { 
  Share2, 
  Copy, 
  Check, 
  Gift, 
  Sparkles, 
  MessageSquare, 
  Send
} from 'lucide-react';

/**
 * Card de Compartilhamento Seguro de Rastreio (LGPD Compliant)
 * Gera links baseados estritamente no tracking_token criptográfico (UUIDv4).
 *
 * @param {Object} props
 * @param {string} props.trackingToken - Token UUID da encomenda
 * @param {string} props.orderNumber - Número da encomenda (ex: MIMOO-Z3ZK9X2R)
 */
export const ShareTrackingCard = ({ trackingToken, orderNumber }) => {
  const [copied, setCopied] = useState(false);

  if (!trackingToken) return null;

  // Link absoluto protegido baseado unicamente no UUID (Sem PII)
  const shareUrl = `${window.location.origin}/?token=${trackingToken}`;

  const handleCopyLink = () => {
    navigator.clipboard.writeText(shareUrl);
    setCopied(true);
    setTimeout(() => setCopied(false), 3000);
  };

  const handleNativeShare = async () => {
    const shareText = `Olha a peça 3D que encomendei na MIMOO LAB! Acompanhe o processo de impressão e acabamento comigo:`;

    if (navigator.share) {
      try {
        await navigator.share({
          title: `Acompanhe minha encomenda 3D na MIMOO LAB (${orderNumber})`,
          text: shareText,
          url: shareUrl,
        });
      } catch (err) {
        if (err.name !== 'AbortError') {
          handleCopyLink();
        }
      }
    } else {
      // Fallback para envio direto via WhatsApp
      const whatsappUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(
        `${shareText}\n${shareUrl}`
      )}`;
      window.open(whatsappUrl, '_blank', 'noopener,noreferrer');
    }
  };

  return (
    <div className="w-full bg-gradient-to-r from-purple-50 via-pink-50 to-amber-50 border-2 border-mimoo-dark rounded-3xl p-5 sm:p-7 shadow-chunky space-y-4 animate-in fade-in duration-300">
      
      {/* Header do Card */}
      <div className="flex items-center gap-3">
        <div className="w-11 h-11 rounded-2xl bg-mimoo-purple text-white border-2 border-mimoo-dark shadow-chunky-sm flex items-center justify-center flex-shrink-0 font-bold">
          <Gift className="w-6 h-6 text-mimoo-yellow" />
        </div>
        <div>
          <div className="flex items-center gap-2">
            <h4 className="font-fredoka font-bold text-lg text-mimoo-dark leading-tight">
              Mostre sua Criação aos Amigos!
            </h4>
            <Sparkles className="w-4 h-4 text-mimoo-purple animate-bounce" />
          </div>
          <p className="text-xs font-nunito text-slate-600">
            Envie este link para amigos ou familiares acompanharem a extrusão da sua peça 3D em tempo real.
          </p>
        </div>
      </div>

      {/* Caixa do Link e Ações */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 pt-1">
        
        {/* Input Readonly do Link */}
        <div className="flex-1 bg-white border-2 border-slate-300 rounded-2xl px-3.5 py-2.5 flex items-center justify-between text-xs font-mono text-slate-700 truncate shadow-inner">
          <span className="truncate pr-2">{shareUrl}</span>
        </div>

        {/* Botões de Ação */}
        <div className="flex items-center gap-2">
          {/* Botão Copiar */}
          <button
            type="button"
            onClick={handleCopyLink}
            className="btn-chunky-pink py-2.5 px-4 text-xs font-fredoka font-bold flex items-center justify-center gap-1.5 whitespace-nowrap shadow-chunky-sm"
          >
            {copied ? <Check className="w-4 h-4 text-emerald-700" /> : <Copy className="w-4 h-4" />}
            <span>{copied ? 'Link Copiado!' : 'Copiar Link'}</span>
          </button>

          {/* Botão Compartilhar */}
          <button
            type="button"
            onClick={handleNativeShare}
            className="btn-chunky-purple py-2.5 px-4 text-xs font-fredoka font-bold flex items-center justify-center gap-1.5 whitespace-nowrap shadow-chunky-sm"
          >
            <Send className="w-4 h-4" />
            <span>Compartilhar</span>
          </button>
        </div>

      </div>

      {/* Aviso de Privacidade */}
      <p className="text-[11px] font-nunito text-slate-400 text-center sm:text-left">
        🛡️ <strong>Privacidade Total:</strong> Este link não exibe seu endereço completo, telefone ou dados bancários.
      </p>

    </div>
  );
};
