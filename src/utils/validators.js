/**
 * Utilitários de Validação Cripto-Algorítmica e Máscaras de Entrada (Frontend MIMOO LAB)
 * Em conformidade com LGPD e padrões de entrada seguros.
 */

/**
 * Remove qualquer caractere não numérico da string.
 * @param {string|number} value
 * @returns {string}
 */
export const cleanNonDigits = (value) => {
  if (value === null || value === undefined) return '';
  return String(value).replace(/\D/g, '');
};

/**
 * Validação algorítmica real dos 2 dígitos verificadores de CPF (Receita Federal do Brasil - Módulo 11).
 * Rejeita tamanhos inválidos, tipos não string e sequências repetitivas (111.111.111-11, etc.).
 * 
 * @param {string} rawCpf
 * @returns {boolean}
 */
export const validateCPF = (rawCpf) => {
  if (!rawCpf || typeof rawCpf !== 'string') return false;

  const cpf = cleanNonDigits(rawCpf);

  // 1. Deve conter exatamente 11 dígitos numéricos
  if (cpf.length !== 11) return false;

  // 2. Rejeita números com todos os dígitos iguais conhecidos
  if (/^(\d)\1{10}$/.test(cpf)) return false;

  // 3. Validação matemática do 1º Dígito Verificador (Módulo 11)
  let sum1 = 0;
  for (let i = 0; i < 9; i++) {
    sum1 += parseInt(cpf.charAt(i), 10) * (10 - i);
  }
  let remainder1 = (sum1 * 10) % 11;
  let digit1 = remainder1 === 10 || remainder1 === 11 ? 0 : remainder1;

  if (digit1 !== parseInt(cpf.charAt(9), 10)) {
    return false;
  }

  // 4. Validação matemática do 2º Dígito Verificador (Módulo 11)
  let sum2 = 0;
  for (let i = 0; i < 10; i++) {
    sum2 += parseInt(cpf.charAt(i), 10) * (11 - i);
  }
  let remainder2 = (sum2 * 10) % 11;
  let digit2 = remainder2 === 10 || remainder2 === 11 ? 0 : remainder2;

  if (digit2 !== parseInt(cpf.charAt(10), 10)) {
    return false;
  }

  return true;
};

/**
 * Máscara progressiva para CPF brasileiro (000.000.000-00).
 * @param {string} value
 * @returns {string}
 */
export const formatCPF = (value) => {
  const digits = cleanNonDigits(value).slice(0, 11);

  if (digits.length <= 3) return digits;
  if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`;
  if (digits.length <= 9) return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6)}`;
  return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9, 11)}`;
};

/**
 * Máscara progressiva para celular/telefone brasileiro:
 * - Fixo (10 dígitos): (00) 0000-0000
 * - Celular (11 dígitos): (00) 00000-0000
 * @param {string} value
 * @returns {string}
 */
export const formatPhone = (value) => {
  const digits = cleanNonDigits(value).slice(0, 11);

  if (digits.length === 0) return '';
  if (digits.length <= 2) return `(${digits}`;
  if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
  if (digits.length <= 10) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
  }
  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7, 11)}`;
};

/**
 * Máscara progressiva para CEP brasileiro (00000-000).
 * @param {string} value
 * @returns {string}
 */
export const formatCEP = (value) => {
  const digits = cleanNonDigits(value).slice(0, 8);

  if (digits.length <= 5) return digits;
  return `${digits.slice(0, 5)}-${digits.slice(5, 8)}`;
};
