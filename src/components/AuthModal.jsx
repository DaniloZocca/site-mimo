import React, { useEffect, useRef, useState } from 'react';
import { useShop } from '../context/ShopContext';
import { X, User, Lock, Mail, ArrowRight, Package, RefreshCw } from 'lucide-react';
import { accountApi } from '../utils/accountApi';
import { formatMoney } from '../utils/pricing';
import { LEGAL_LINKS } from '../content/legal';

/**
 * Área do Cliente (M-26): login, cadastro, "Esqueci minha senha", "Nova senha" e "Meus pedidos",
 * todos pela API /api/conta/* (sessão em cookie da API). Mesmo visual do modal anterior.
 */

const Field = ({ label, icon: Icon, ...inputProps }) => (
  <div>
    <label className="block text-slate-600 font-fredoka font-semibold mb-1">
      {label}
      <div className="relative mt-1">
        <input
          {...inputProps}
          className="w-full rounded-xl border-2 border-mimoo-dark px-3 py-2 pl-9 text-xs font-nunito font-normal text-slate-800 focus:outline-none focus:ring-2 focus:ring-mimoo-purple"
        />
        <Icon className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" aria-hidden="true" />
      </div>
    </label>
  </div>
);

const FormMessage = ({ error, success }) => {
  if (error) {
    return (
      <p role="alert" className="text-xs font-nunito text-red-600 bg-red-50 border-2 border-red-200 rounded-xl px-3 py-2">
        {error}
      </p>
    );
  }
  if (success) {
    return (
      <p role="status" className="text-xs font-nunito text-emerald-800 bg-mimoo-mint-light border-2 border-emerald-300 rounded-xl px-3 py-2">
        {success}
      </p>
    );
  }
  return null;
};

const SubmitButton = ({ busy, children }) => (
  <button
    type="submit"
    disabled={busy}
    className="w-full btn-chunky-purple py-3 text-xs font-bold flex items-center justify-center gap-2 mt-2 shadow-chunky disabled:opacity-60 disabled:cursor-wait"
  >
    <span>{busy ? 'Só um instante...' : children}</span>
    {!busy && <ArrowRight className="w-4 h-4" aria-hidden="true" />}
  </button>
);

const formatDate = (iso) => {
  if (!iso) return '';
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('pt-BR');
};

const MyOrders = () => {
  const [state, setState] = useState({ loading: true, error: null, orders: [] });

  useEffect(() => {
    let cancelled = false;
    accountApi.orders()
      .then((body) => {
        if (!cancelled) setState({ loading: false, error: null, orders: Array.isArray(body.data) ? body.data : [] });
      })
      .catch((err) => {
        if (!cancelled) setState({ loading: false, error: err.message, orders: [] });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const openTracking = (token) => {
    // Mesmo caminho do botão "Acompanhar" dos e-mails: a loja abre o rastreio pelo token.
    window.location.assign(`${window.location.pathname}?token=${encodeURIComponent(token)}`);
  };

  if (state.loading) {
    return (
      <div className="space-y-2" aria-busy="true">
        {[1, 2].map((i) => (
          <div key={i} className="h-16 bg-slate-100 rounded-2xl border-2 border-mimoo-dark/10 animate-pulse" />
        ))}
      </div>
    );
  }

  if (state.error) {
    return <FormMessage error={state.error} />;
  }

  if (state.orders.length === 0) {
    return (
      <div className="text-center bg-mimoo-purple-tint p-4 rounded-2xl border-2 border-mimoo-dark/20 space-y-1">
        <p className="text-2xl" aria-hidden="true">🐮</p>
        <p className="font-fredoka font-bold text-slate-800">Nenhum pedido por aqui ainda</p>
        <p className="text-slate-500">As encomendas feitas com este e-mail aparecem aqui.</p>
      </div>
    );
  }

  return (
    <ul className="space-y-2 max-h-64 overflow-y-auto pr-1">
      {state.orders.map((order) => (
        <li key={order.order_number} className="bg-slate-50 p-3 rounded-2xl border-2 border-mimoo-dark/20 flex items-center justify-between gap-3">
          <div className="min-w-0 space-y-1">
            <p className="font-fredoka font-bold text-slate-800 truncate">#{order.order_number}</p>
            <span className="badge-chunky inline-block leading-tight bg-mimoo-purple-soft text-mimoo-purple-dark border-mimoo-purple-light">
              {order.status_label}
            </span>
            <p className="text-slate-500">
              {formatDate(order.created_at)} • {order.items_count} {order.items_count === 1 ? 'peça' : 'peças'} • {formatMoney(order.total_cents)}
            </p>
          </div>
          <button
            type="button"
            onClick={() => openTracking(order.tracking_token)}
            className="btn-chunky-white px-3 py-2 text-[11px] font-bold flex items-center gap-1 shrink-0"
          >
            <Package className="w-3.5 h-3.5" aria-hidden="true" />
            Acompanhar
          </button>
        </li>
      ))}
    </ul>
  );
};

export const AuthModal = () => {
  const {
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
    showToast,
  } = useShop();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);

  const dialogRef = useRef(null);

  const close = () => setIsAuthModalOpen(false);

  // Esc fecha; Tab fica dentro do modal; ao abrir o foco vai para o modal e ao fechar volta para onde estava.
  useEffect(() => {
    if (!isAuthModalOpen) return undefined;
    const previouslyFocused = document.activeElement;
    const focusables = () => [...(dialogRef.current?.querySelectorAll(
      'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
    ) || [])];
    (focusables().find((el) => el.tagName === 'INPUT') || dialogRef.current)?.focus();

    const onKey = (e) => {
      if (e.key === 'Escape') {
        setIsAuthModalOpen(false);
        return;
      }
      if (e.key !== 'Tab') return;
      const items = focusables();
      if (items.length === 0) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !dialogRef.current.contains(document.activeElement))) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || !dialogRef.current.contains(document.activeElement))) {
        e.preventDefault();
        first.focus();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('keydown', onKey);
      if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
    };
  }, [isAuthModalOpen, setIsAuthModalOpen]);


  if (!isAuthModalOpen) return null;

  const goTo = (view) => {
    setAuthModalView(view);
    setError(null);
    setSuccess(null);
    setPassword('');
    setPasswordConfirmation('');
  };

  const run = async (action) => {
    setBusy(true);
    setError(null);
    setSuccess(null);
    try {
      await action();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const handleLogin = (e) => {
    e.preventDefault();
    run(async () => {
      const customer = await login({ email, password });
      setPassword('');
      if (!pendingVerification) {
        showToast(`🐮 Que bom te ver, ${customer.name.split(' ')[0]}!`);
      }
    });
  };

  const handleRegister = (e) => {
    e.preventDefault();
    run(async () => {
      // M-44: a resposta é a mesma para e-mail novo e para e-mail que já tem conta; ninguém sai logado.
      const { message } = await register({ name, email, password, password_confirmation: passwordConfirmation });
      goTo('login');
      setSuccess(message);
    });
  };

  const handleForgot = (e) => {
    e.preventDefault();
    run(async () => {
      const { message } = await accountApi.forgotPassword(email);
      setSuccess(message);
    });
  };

  const handleReset = (e) => {
    e.preventDefault();
    run(async () => {
      const { message } = await accountApi.resetPassword({
        token: resetToken || '',
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setResetToken(null);
      // Trocar a senha encerra as sessões abertas da conta (inclusive esta, se estava logada).
      await refreshUser();
      goTo('login');
      setSuccess(message);
    });
  };

  const handleResend = () => {
    run(async () => {
      const { message } = await accountApi.resendVerification();
      setSuccess(message);
    });
  };

  const handleLogout = () => {
    run(async () => {
      await logout();
      // Nada do formulário anterior fica na tela para a próxima pessoa.
      setEmail('');
      setName('');
      setIsAuthModalOpen(false);
      showToast('Você saiu da sua conta.', 'info');
    });
  };

  // Link de nova senha aberto com alguém logado: mostra o formulário de nova senha, não o perfil.
  const showProfile = user.isLoggedIn && !(authModalView === 'reset' && resetToken);
  const isTabView = authModalView === 'login' || authModalView === 'register';
  const subtitle = {
    login: 'Acesse seus mimos 3D e acompanhe a fila de impressão!',
    register: 'Crie sua conta e veja todas as suas encomendas num lugar só!',
    forgot: 'Sem problema! A gente te manda um link para criar uma senha nova.',
    reset: 'Escolha uma senha nova para a sua conta.',
  }[authModalView];

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-mimoo-dark/60 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200">

      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="auth-modal-title"
        tabIndex={-1}
        ref={dialogRef}
        className="relative w-full max-w-md bg-white rounded-3xl border-2 border-mimoo-dark shadow-chunky-xl overflow-hidden p-6 sm:p-8"
      >

        {/* Close button */}
        <button
          type="button"
          onClick={close}
          aria-label="Fechar"
          className="absolute top-4 right-4 p-2 rounded-xl border-2 border-mimoo-dark bg-white shadow-chunky-sm hover:bg-mimoo-pink-light transition-all"
        >
          <X className="w-5 h-5" aria-hidden="true" />
        </button>

        {/* Mascot Avatar Header */}
        <div className="text-center space-y-2 mb-6">
          <div className="w-16 h-16 rounded-3xl border-2 border-mimoo-dark bg-mimoo-purple-tint p-2 mx-auto shadow-chunky-sm flex items-center justify-center animate-bounce-soft">
            <img
              src="/assets/logo-mascot.png"
              alt="Mascote Mimoo"
              className="w-full h-full object-contain"
            />
          </div>

          <h3 id="auth-modal-title" className="font-fredoka font-bold text-2xl text-mimoo-dark">
            {showProfile ? `Olá, ${user.name.split(' ')[0]}! 🐮` : 'Área do Cliente MIMOO'}
          </h3>

          <p className="text-xs text-slate-500 font-nunito">
            {showProfile
              ? 'Acompanhe aqui as suas encomendas sob encomenda.'
              : subtitle}
          </p>
        </div>

        {showProfile ? (
          /* Logged-in profile panel */
          <div className="space-y-4 font-nunito text-xs">
            <div className="bg-slate-50 p-4 rounded-2xl border-2 border-mimoo-dark/20 space-y-2">
              <div className="flex justify-between gap-3">
                <span className="text-slate-400 font-fredoka">Nome:</span>
                <strong className="text-slate-800 text-right break-all">{user.name}</strong>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-slate-400 font-fredoka">E-mail:</span>
                <strong className="text-slate-800 text-right break-all">{user.email}</strong>
              </div>
            </div>

            <div className="space-y-2">
              <h4 className="font-fredoka font-bold text-sm text-mimoo-dark">Meus pedidos</h4>
              {user.emailVerified ? (
                <MyOrders />
              ) : (
                <div className="bg-mimoo-yellow-light p-4 rounded-2xl border-2 border-mimoo-dark/20 space-y-2">
                  <p className="text-slate-700">
                    📬 Confirme seu e-mail para ver seus pedidos. Mandamos o link para <strong>{user.email}</strong>.
                  </p>
                  <button
                    type="button"
                    onClick={handleResend}
                    disabled={busy}
                    className="btn-chunky-white px-3 py-2 text-[11px] font-bold flex items-center gap-1 disabled:opacity-60"
                  >
                    <RefreshCw className="w-3.5 h-3.5" aria-hidden="true" />
                    Reenviar e-mail de confirmação
                  </button>
                </div>
              )}
            </div>

            <FormMessage error={error} success={success} />

            <button
              type="button"
              onClick={handleLogout}
              disabled={busy}
              className="w-full btn-chunky-white py-2.5 text-xs font-bold text-red-500 hover:bg-red-50 disabled:opacity-60"
            >
              Sair da Conta
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            {/* Tabs */}
            {isTabView && (
              <div className="grid grid-cols-2 gap-2 bg-slate-100 p-1 rounded-2xl border border-slate-200 text-xs font-fredoka">
                <button
                  type="button"
                  onClick={() => goTo('login')}
                  className={`py-2 rounded-xl transition-all font-bold ${
                    authModalView === 'login' ? 'bg-mimoo-purple text-white shadow-chunky-sm' : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  Entrar
                </button>
                <button
                  type="button"
                  onClick={() => goTo('register')}
                  className={`py-2 rounded-xl transition-all font-bold ${
                    authModalView === 'register' ? 'bg-mimoo-purple text-white shadow-chunky-sm' : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  Cadastrar
                </button>
              </div>
            )}

            {authModalView === 'login' && (
              <form onSubmit={handleLogin} className="space-y-3 font-nunito text-xs">
                {pendingVerification && (
                  <FormMessage success="Entre na sua conta para terminar de confirmar o e-mail. 💜" />
                )}
                <Field label="Seu E-mail:" icon={Mail} type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="seuemail@exemplo.com" />
                <Field label="Senha:" icon={Lock} type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" />
                <button type="button" onClick={() => goTo('forgot')} className="text-mimoo-purple-dark font-fredoka font-semibold underline underline-offset-2">
                  Esqueci minha senha
                </button>
                <FormMessage error={error} success={success} />
                <SubmitButton busy={busy}>Acessar Minha Conta</SubmitButton>
              </form>
            )}

            {authModalView === 'register' && (
              <form onSubmit={handleRegister} className="space-y-3 font-nunito text-xs">
                <Field label="Seu Nome:" icon={User} type="text" required autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex: Ana Clara Silva" />
                <Field label="Seu E-mail:" icon={Mail} type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="seuemail@exemplo.com" />
                <Field label="Senha:" icon={Lock} type="password" required minLength={8} maxLength={72} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Letras e números, 8 ou mais" />
                <Field label="Repita a senha:" icon={Lock} type="password" required autoComplete="new-password" value={passwordConfirmation} onChange={(e) => setPasswordConfirmation(e.target.value)} placeholder="••••••••" />
                <FormMessage error={error} success={success} />
                <SubmitButton busy={busy}>Criar Conta de Amigo MIMOO</SubmitButton>
                <p className="text-xs text-slate-500 text-center leading-snug">
                  Ao criar a conta, você concorda com os{' '}
                  <a href={LEGAL_LINKS.terms} target="_blank" rel="noopener noreferrer" className="font-bold text-mimoo-purple-dark underline underline-offset-2">Termos de Uso<span className="sr-only"> (abre em nova aba)</span></a>
                  {' '}e a{' '}
                  <a href={LEGAL_LINKS.privacy} target="_blank" rel="noopener noreferrer" className="font-bold text-mimoo-purple-dark underline underline-offset-2">Política de Privacidade<span className="sr-only"> (abre em nova aba)</span></a>.
                </p>
              </form>
            )}

            {authModalView === 'forgot' && (
              <form onSubmit={handleForgot} className="space-y-3 font-nunito text-xs">
                <Field label="E-mail da sua conta:" icon={Mail} type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="seuemail@exemplo.com" />
                <FormMessage error={error} success={success} />
                <SubmitButton busy={busy}>Enviar link de nova senha</SubmitButton>
                <button type="button" onClick={() => goTo('login')} className="w-full text-mimoo-purple-dark font-fredoka font-semibold underline underline-offset-2">
                  Voltar para Entrar
                </button>
              </form>
            )}

            {authModalView === 'reset' && (
              <form onSubmit={handleReset} className="space-y-3 font-nunito text-xs">
                <Field label="E-mail da sua conta:" icon={Mail} type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="seuemail@exemplo.com" />
                <Field label="Nova senha:" icon={Lock} type="password" required minLength={8} maxLength={72} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Letras e números, 8 ou mais" />
                <Field label="Repita a nova senha:" icon={Lock} type="password" required autoComplete="new-password" value={passwordConfirmation} onChange={(e) => setPasswordConfirmation(e.target.value)} placeholder="••••••••" />
                <FormMessage error={error} success={success} />
                <SubmitButton busy={busy}>Salvar nova senha</SubmitButton>
                <button type="button" onClick={() => goTo('forgot')} className="w-full text-mimoo-purple-dark font-fredoka font-semibold underline underline-offset-2">
                  Pedir outro link
                </button>
              </form>
            )}
          </div>
        )}

      </div>

    </div>
  );
};
