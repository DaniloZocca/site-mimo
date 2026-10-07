/**
 * Endereço base da API Laravel.
 * Em produção, defina VITE_API_URL no build (ex.: https://api.mimoo.com.br, sem "/api" no final).
 * Sem a variável, vale o backend local do `php artisan serve`.
 */
const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000').replace(/\/+$/, '');

export const API_BASE = `${API_URL}/api`;
