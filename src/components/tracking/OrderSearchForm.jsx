import React, { useState, useEffect } from 'react';
import { 
  Search, 
  Key, 
  Package, 
  ShieldCheck, 
  AlertCircle, 
  Loader2, 
  Sparkles, 
  Mail, 
  FileText,
  Clock
} from 'lucide-react';
import { formatCPF, cleanNonDigits } from '../../utils/validators';

/**
 * Formulário de Busca Pública de Rastreio da MIMOO LAB
 * Suporta busca por Chave Composta (Anti-IDOR) ou Token Criptográfico (UUID).
 *
 * @param {Object} props
 * @param {Function} props.onSearch - Callback que recebe { trackingToken } ou { orderNumber, identifier }
 * @param {boolean} [props.loading]
 * @param {string} [props.initialOrderNumber]
 * @param {string|null} [props.error]
 * @param {boolean} [props.rateLimited]
 */
export const OrderSearchForm = ({
  onSearch,
  loading = false,
  initialOrderNumber = '',
  error = null,
  rateLimited = false,
}) => {
  const [activeTab, setActiveTab] = useState('composite'); // 'composite' | 'token'
  
  // Campos da Aba 1 (Chave Composta)
  const [orderNumber, setOrderNumber] = useState(initialOrderNumber || '');
  const [identifier, setIdentifier] = useState('');
  const [identifierType, setIdentifierType] = useState('cpf'); // 'cpf' | 'email'

  // Campos da Aba 2 (Token Direto)
  const [trackingToken, setTrackingToken] = useState('');

  // Erros de validação do formulário local
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    if (initialOrderNumber) {
      setOrderNumber(initialOrderNumber.toUpperCase());
    }
  }, [initialOrderNumber]);

  // Manipulador inteligente para o campo de identificador (detecta CPF ou E-mail)
  const handleIdentifierChange = (e) => {
    const val = e.target.value;
    setFormError(null);

    // Se começar com números e não tiver @, trata como CPF
    const isNumericStart = /^\d/.test(val.trim());

    if (isNumericStart && !val.includes('@')) {
      setIdentifierType('cpf');
      setIdentifier(formatCPF(val));
    } else {
      setIdentifierType('email');
      setIdentifier(val);
    }
  };

  const handleOrderNumberChange = (e) => {
    setFormError(null);
    let val = e.target.value.toUpperCase();
    setOrderNumber(val);
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    setFormError(null);

    if (activeTab === 'composite') {
      const cleanOrder = orderNumber.trim().toUpperCase();
      const cleanId = identifier.trim();

      if (!cleanOrder) {
        setFormError('Por favor, informe o número da sua encomenda (ex: MIMOO-XXXX).');
        return;
      }

      if (!cleanId || (identifierType === 'cpf' && cleanNonDigits(cleanId).length !== 11)) {
        setFormError('Por favor, informe o CPF completo (11 números) ou o e-mail da compra.');
        return;
      }

      onSearch({
        orderNumber: cleanOrder.startsWith('MIMOO-') ? cleanOrder : `MIMOO-${cleanOrder.replace(/^#/, '')}`,
        identifier: cleanId,
      });
    } else {
      const cleanToken = trackingToken.trim();

      if (!cleanToken) {
        setFormError('Por favor, cole o Token Secreto de Rastreio (UUID).');
        return;
      }

      const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
      if (!uuidRegex.test(cleanToken)) {
        setFormError('O token informado não possui o formato de chave criptográfica válido.');
        return;
      }

      onSearch({ trackingToken: cleanToken });
    }
  };

  return (
    <div className="w-full max-w-xl mx-auto bg-white border-2 border-mimoo-dark rounded-3xl p-5 sm:p-7 shadow-chunky transition-all animate-in fade-in duration-300">
      
      {/* Header do Card */}
      <div className="text-center space-y-1.5 mb-6">
        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-100 border border-purple-200 text-mimoo-purple text-xs font-fredoka font-bold">
          <Sparkles className="w-3.5 h-3.5 text-mimoo-yellow" />
          <span>Central de Rastreamento 3D</span>
        </div>
        <h2 className="font-fredoka font-bold text-2xl text-mimoo-dark">
          Acompanhe sua Criação
        </h2>
        <p className="text-xs font-nunito text-slate-500 max-w-sm mx-auto">
          Veja em qual impressora sua peça está sendo fabricada e o tempo restante de extrusão.
        </p>
      </div>

      {/* Seletor de Abas (Tabs) */}
      <div className="grid grid-cols-2 p-1 rounded-2xl bg-slate-100 border border-slate-200 mb-5 font-fredoka text-xs">
        <button
          type="button"
          onClick={() => { setActiveTab('composite'); setFormError(null); }}
          className={`py-2.5 px-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all ${
            activeTab === 'composite'
              ? 'bg-white text-mimoo-purple shadow-chunky-sm border border-mimoo-dark/20'
              : 'text-slate-500 hover:text-slate-800'
          }`}
        >
          <Package className="w-4 h-4" />
          <span>Nº do Pedido + CPF</span>
        </button>

        <button
          type="button"
          onClick={() => { setActiveTab('token'); setFormError(null); }}
          className={`py-2.5 px-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all ${
            activeTab === 'token'
              ? 'bg-white text-mimoo-purple shadow-chunky-sm border border-mimoo-dark/20'
              : 'text-slate-500 hover:text-slate-800'
          }`}
        >
          <Key className="w-4 h-4" />
          <span>Token Secreto</span>
        </button>
      </div>

      {/* Exibição de Alertas de Erro e Rate Limit */}
      {(error || formError || rateLimited) && (
        <div className={`p-4 rounded-2xl border-2 mb-5 flex items-start gap-3 text-xs font-nunito animate-in shake ${
          rateLimited 
            ? 'bg-amber-50 border-amber-300 text-amber-900' 
            : 'bg-pink-50 border-pink-300 text-pink-900'
        }`}>
          {rateLimited ? (
            <Clock className="w-5 h-5 flex-shrink-0 text-amber-600 mt-0.5" />
          ) : (
            <AlertCircle className="w-5 h-5 flex-shrink-0 text-pink-600 mt-0.5" />
          )}
          <div className="space-y-0.5">
            <strong className="block font-fredoka font-bold">
              {rateLimited ? 'Muitas Consultas Consecutivas' : 'Atenção'}
            </strong>
            <span>{error || formError}</span>
          </div>
        </div>
      )}

      {/* Formulário Principal */}
      <form onSubmit={handleSubmit} className="space-y-4 font-nunito">
        
        {activeTab === 'composite' ? (
          <>
            {/* Campo 1: Número da Encomenda */}
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Número da Encomenda *
              </label>
              <div className="relative">
                <input
                  type="text"
                  placeholder="Ex: MIMOO-Z3ZK9X2R"
                  value={orderNumber}
                  onChange={handleOrderNumberChange}
                  disabled={loading || rateLimited}
                  className="w-full pl-3.5 pr-10 py-3 rounded-2xl border-2 border-slate-300 bg-white text-sm uppercase font-mono tracking-wider text-slate-800 outline-none focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20 transition-all disabled:opacity-60"
                />
                <Package className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
              </div>
              <span className="text-[11px] text-slate-400 mt-1 block">
                Disponível no recibo do seu pedido ou tela de confirmação.
              </span>
            </div>

            {/* Campo 2: CPF ou E-mail */}
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1 flex items-center justify-between">
                <span>CPF ou E-mail da Compra *</span>
                <span className="text-[10px] text-mimoo-purple font-semibold">
                  {identifierType === 'cpf' ? 'Modo CPF' : 'Modo E-mail'}
                </span>
              </label>
              <div className="relative">
                <input
                  type="text"
                  placeholder="Seu CPF (000.000.000-00) ou e-mail"
                  value={identifier}
                  onChange={handleIdentifierChange}
                  disabled={loading || rateLimited}
                  className="w-full pl-3.5 pr-10 py-3 rounded-2xl border-2 border-slate-300 bg-white text-sm text-slate-800 outline-none focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20 transition-all disabled:opacity-60"
                />
                {identifierType === 'cpf' ? (
                  <FileText className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                ) : (
                  <Mail className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                )}
              </div>
              <span className="text-[11px] text-slate-400 mt-1 block">
                Usado como autenticação de segurança para proteger sua privacidade.
              </span>
            </div>
          </>
        ) : (
          /* Aba 2: Token Criptográfico Direto */
          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Token Secreto de Rastreio (UUID) *
            </label>
            <div className="relative">
              <input
                type="text"
                placeholder="Cole seu token (ex: 3e9b4a1e-3c05-4879-b42d-...)"
                value={trackingToken}
                onChange={(e) => { setFormError(null); setTrackingToken(e.target.value); }}
                disabled={loading || rateLimited}
                className="w-full pl-3.5 pr-10 py-3 rounded-2xl border-2 border-slate-300 bg-white text-xs font-mono text-slate-800 outline-none focus:border-mimoo-purple focus:ring-2 focus:ring-mimoo-purple/20 transition-all disabled:opacity-60"
              />
              <Key className="absolute right-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            </div>
            <span className="text-[11px] text-slate-400 mt-1 block">
              Chave de acesso único gerada na finalização do pedido.
            </span>
          </div>
        )}

        {/* Botão de Ação */}
        <button
          type="submit"
          disabled={loading || rateLimited}
          className="w-full btn-chunky-purple py-3.5 px-6 font-fredoka font-bold text-sm flex items-center justify-center gap-2 shadow-chunky transition-transform hover:scale-[1.01] active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed mt-2"
        >
          {loading ? (
            <>
              <Loader2 className="w-4 h-4 animate-spin text-white" />
              <span>Consultando Fazenda 3D...</span>
            </>
          ) : (
            <>
              <Search className="w-4 h-4" />
              <span>Rastrear Minha Encomenda</span>
            </>
          )}
        </button>

        {/* Aviso de Privacidade LGPD */}
        <div className="flex items-center justify-center gap-2 pt-2 text-[11px] text-slate-400 font-nunito text-center">
          <ShieldCheck className="w-4 h-4 text-emerald-600 flex-shrink-0" />
          <span>
            Seus dados estão protegidos. Endereço e CPF completos nunca são exibidos publicamente (LGPD).
          </span>
        </div>

      </form>
    </div>
  );
};
