import { z } from 'zod';
import { cleanNonDigits, validateCPF } from '../utils/validators';

/**
 * Schema de Validação para Consulta Pública de Rastreio (Anti-IDOR / LGPD)
 * Suporta busca por Token Criptográfico (UUID) ou por Chave Composta (Número do Pedido + CPF/E-mail).
 */
export const orderSearchSchema = z
  .object({
    // Opção 1: Token Único Criptográfico (UUIDv4)
    trackingToken: z
      .string()
      .trim()
      .uuid('O token de rastreio deve ser um identificador UUID válido.')
      .optional()
      .or(z.literal('')),

    // Opção 2: Número da Encomenda (ex: MIMOO-Z3ZK9X2R)
    orderNumber: z
      .string()
      .trim()
      .min(3, 'O número da encomenda deve ter pelo menos 3 caracteres.')
      .max(32, 'Número da encomenda muito longo.')
      .optional()
      .or(z.literal('')),

    // Fator de Autenticação Secundário: E-mail de compra ou CPF
    identifier: z
      .string()
      .trim()
      .min(3, 'Informe o CPF completo (11 números) ou o e-mail da compra.')
      .max(150, 'Identificador muito longo.')
      .optional()
      .or(z.literal('')),
  })
  .refine(
    (data) => {
      const hasToken = Boolean(data.trackingToken && data.trackingToken.length > 0);
      const hasOrderAndIdentifier = Boolean(
        data.orderNumber && data.orderNumber.length > 0 && data.identifier && data.identifier.length > 0
      );

      return hasToken || hasOrderAndIdentifier;
    },
    {
      message: 'Informe o Token de Rastreio OU o Número da Encomenda acompanhado do seu E-mail ou CPF.',
      path: ['orderNumber'],
    }
  );

/**
 * Validador específico para busca rápida por chave composta.
 */
export const compositeKeySearchSchema = z.object({
  orderNumber: z
    .string()
    .trim()
    .min(3, 'Informe o número da encomenda (ex: MIMOO-XXXX).'),
  identifier: z
    .string()
    .trim()
    .min(3, 'Informe seu e-mail de compra ou CPF para validação de segurança.')
    .refine(
      (val) => {
        if (val.includes('@')) {
          return z.string().email().safeParse(val).success;
        }
        const digits = cleanNonDigits(val);
        return digits.length === 11;
      },
      {
        message: 'Informe o CPF completo (11 números) ou o e-mail da compra.',
      }
    ),
});
