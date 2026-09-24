import React, { useState } from 'react';
import { useShop } from '../context/ShopContext';
import { X, User, Lock, Mail, Sparkles, ArrowRight, ShieldCheck, Check } from 'lucide-react';

export const AuthModal = () => {
  const { isAuthModalOpen, setIsAuthModalOpen, user, setUser, showToast } = useShop();
  const [tab, setTab] = useState('login'); // 'login' | 'register'
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');

  if (!isAuthModalOpen) return null;

  const handleSubmit = (e) => {
    e.preventDefault();
    setUser({
      name: name || (tab === 'login' ? 'Fernanda Oliveira' : 'Novo Amigo'),
      email: email || 'cliente@mimoolab.com.br',
      isLoggedIn: true,
      ordersCount: 2,
    });
    setIsAuthModalOpen(false);
    showToast(`🐮 Bem-vindo(a) à MIMOO LAB, ${name || 'Fernanda'}!`);
  };

  const handleLogout = () => {
    setUser({
      name: '',
      email: '',
      isLoggedIn: false,
      ordersCount: 0,
    });
    setIsAuthModalOpen(false);
    showToast('Você saiu da sua conta.', 'info');
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-mimoo-dark/60 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200">
      
      <div className="relative w-full max-w-md bg-white rounded-3xl border-2 border-mimoo-dark shadow-chunky-xl overflow-hidden p-6 sm:p-8">
        
        {/* Close button */}
        <button
          onClick={() => setIsAuthModalOpen(false)}
          className="absolute top-4 right-4 p-2 rounded-xl border-2 border-mimoo-dark bg-white shadow-chunky-sm hover:bg-mimoo-pink-light transition-all"
        >
          <X className="w-5 h-5" />
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

          <h3 className="font-fredoka font-bold text-2xl text-mimoo-dark">
            {user.isLoggedIn ? `Olá, ${user.name.split(' ')[0]}! 🐮` : 'Área do Cliente MIMOO'}
          </h3>

          <p className="text-xs text-slate-500 font-nunito">
            {user.isLoggedIn 
              ? 'Gerencie seus pedidos sob encomenda e endereços.' 
              : 'Acesse seus mimos 3D e acompanhe a fila de impressão!'}
          </p>
        </div>

        {user.isLoggedIn ? (
          /* Logged-in profile panel */
          <div className="space-y-4 font-nunito text-xs">
            <div className="bg-slate-50 p-4 rounded-2xl border-2 border-mimoo-dark/20 space-y-2">
              <div className="flex justify-between">
                <span className="text-slate-400 font-fredoka">Nome:</span>
                <strong className="text-slate-800">{user.name}</strong>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400 font-fredoka">E-mail:</span>
                <strong className="text-slate-800">{user.email}</strong>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400 font-fredoka">Pedidos Ativos:</span>
                <span className="badge-chunky bg-mimoo-mint-light text-emerald-800 border-emerald-400">
                  2 pedidos na fila
                </span>
              </div>
            </div>

            <button
              onClick={handleLogout}
              className="w-full btn-chunky-white py-2.5 text-xs font-bold text-red-500 hover:bg-red-50"
            >
              Sair da Conta
            </button>
          </div>
        ) : (
          /* Login / Register Form */
          <div className="space-y-4">
            {/* Tabs */}
            <div className="grid grid-cols-2 gap-2 bg-slate-100 p-1 rounded-2xl border border-slate-200 text-xs font-fredoka">
              <button
                onClick={() => setTab('login')}
                className={`py-2 rounded-xl transition-all font-bold ${
                  tab === 'login' ? 'bg-mimoo-purple text-white shadow-chunky-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Entrar
              </button>
              <button
                onClick={() => setTab('register')}
                className={`py-2 rounded-xl transition-all font-bold ${
                  tab === 'register' ? 'bg-mimoo-purple text-white shadow-chunky-sm' : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Cadastrar
              </button>
            </div>

            <form onSubmit={handleSubmit} className="space-y-3 font-nunito text-xs">
              {tab === 'register' && (
                <div>
                  <label className="block text-slate-600 font-fredoka font-semibold mb-1">Seu Nome:</label>
                  <div className="relative">
                    <input
                      type="text"
                      required
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="Ex: Fernanda Oliveira"
                      className="w-full rounded-xl border-2 border-mimoo-dark px-3 py-2 pl-9 text-xs focus:outline-none"
                    />
                    <User className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                  </div>
                </div>
              )}

              <div>
                <label className="block text-slate-600 font-fredoka font-semibold mb-1">Seu E-mail:</label>
                <div className="relative">
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="seuemail@exemplo.com"
                    className="w-full rounded-xl border-2 border-mimoo-dark px-3 py-2 pl-9 text-xs focus:outline-none"
                  />
                  <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                </div>
              </div>

              <div>
                <label className="block text-slate-600 font-fredoka font-semibold mb-1">Senha:</label>
                <div className="relative">
                  <input
                    type="password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full rounded-xl border-2 border-mimoo-dark px-3 py-2 pl-9 text-xs focus:outline-none"
                  />
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                </div>
              </div>

              <button
                type="submit"
                className="w-full btn-chunky-purple py-3 text-xs font-bold flex items-center justify-center gap-2 mt-2 shadow-chunky"
              >
                <span>{tab === 'login' ? 'Acessar Minha Conta' : 'Criar Conta de Amigo MIMOO'}</span>
                <ArrowRight className="w-4 h-4" />
              </button>
            </form>
          </div>
        )}

      </div>

    </div>
  );
};
