import { API_BASE } from '../config/api';

/**
 * Chamadas da conta de cliente (M-26): /api/conta/*.
 * A sessão fica num cookie httpOnly da API (o JavaScript não lê); por isso todas as chamadas vão com
 * credentials: 'include'. Requisições que mudam algo levam o cabeçalho X-XSRF-TOKEN com o valor do
 * cookie XSRF-TOKEN, que a API entrega em GET /api/conta/csrf-cookie (funciona porque loja e API
 * ficam no mesmo domínio, com SESSION_DOMAIN no backend). O resto da loja não usa cookie.
 */
const ACCOUNT_BASE = `${API_BASE}/conta`;

export class AccountApiError extends Error {
  constructor(message, status, body = {}) {
    super(message);
    this.name = 'AccountApiError';
    this.status = status;
    this.code = body.code || null;
    this.errors = body.errors || {};
  }

  /** Primeira mensagem do campo (422) ou a mensagem geral. */
  fieldMessage(field) {
    return this.errors?.[field]?.[0] || null;
  }
}

const readXsrfCookie = () => {
  const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
  return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
};

const ensureXsrfCookie = async () => {
  if (readXsrfCookie()) return;
  await fetch(`${ACCOUNT_BASE}/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } });
};

const FALLBACK_MESSAGES = {
  401: 'Sua sessão terminou. Entre de novo, por favor.',
  419: 'A página ficou aberta muito tempo. Tente de novo.',
  429: 'Muitas tentativas seguidas. Espere um minutinho e tente de novo.',
};

const request = async (method, path, payload, { retryOnCsrf = true } = {}) => {
  const isWrite = method !== 'GET';
  if (isWrite) await ensureXsrfCookie();

  const headers = { Accept: 'application/json' };
  if (isWrite) {
    headers['Content-Type'] = 'application/json';
    const xsrf = readXsrfCookie();
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
  }

  let response;
  try {
    response = await fetch(`${ACCOUNT_BASE}${path}`, {
      method,
      credentials: 'include',
      headers,
      body: isWrite ? JSON.stringify(payload || {}) : undefined,
    });
  } catch {
    throw new AccountApiError('Não conseguimos falar com a loja agora. Confira sua internet e tente de novo.', 0);
  }

  // Token CSRF vencido (sessão expirou): busca outro e tenta uma única vez.
  if (response.status === 419 && retryOnCsrf) {
    await fetch(`${ACCOUNT_BASE}/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } });
    return request(method, path, payload, { retryOnCsrf: false });
  }

  if (response.status === 204) return {};

  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const firstFieldError = Object.values(body.errors || {})[0]?.[0];
    throw new AccountApiError(
      firstFieldError || FALLBACK_MESSAGES[response.status] || body.message || 'Algo deu errado. Tente de novo.',
      response.status,
      body
    );
  }

  return body;
};

export const accountApi = {
  me: () => request('GET', '/me'),
  register: (data) => request('POST', '/register', data),
  login: (data) => request('POST', '/login', data),
  logout: () => request('POST', '/logout'),
  forgotPassword: (email) => request('POST', '/forgot-password', { email }),
  resetPassword: (data) => request('POST', '/reset-password', data),
  resendVerification: () => request('POST', '/email/verification-notification'),
  /** Dados do link do e-mail (?verificar=1&id&hash&expires&signature). */
  verifyEmail: ({ id, hash, expires, signature }) =>
    request(
      'POST',
      `/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${new URLSearchParams({ expires, signature })}`
    ),
  orders: () => request('GET', '/orders'),
};
