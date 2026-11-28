/**
 * Endereço base da API Laravel.
 * Em produção, defina VITE_API_URL no build (https://api.mimoolab.com.br, sem "/api" no final;
 * a CSP do public/.htaccess só libera esse endereço).
 * Sem a variável, vale o backend local do `php artisan serve`.
 */
const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000').replace(/\/+$/, '');

export const API_BASE = `${API_URL}/api`;
