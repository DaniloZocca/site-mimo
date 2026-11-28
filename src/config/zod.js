import { z } from 'zod';

// M-86: a CSP da loja bloqueia `new Function`; sem isto o Zod testa se pode usá-lo e deixa um aviso no console.
// O modo sem JIT é o mesmo que o Zod já usa quando o teste falha.
z.config({ jitless: true });
