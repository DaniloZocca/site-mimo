-- M-41: exclui a conta de cliente de UM e-mail, a pedido do próprio cliente (Política de Privacidade).
-- Roteiro completo, com as conferências antes de rodar: docs/runbook-excluir-conta-cliente.md.
-- Troque 'EMAIL_DA_CONTA' (em todas as linhas abaixo) pelo e-mail da conta, entre aspas simples.
-- Apaga: o cadastro (customers), o token de "nova senha" (customer_password_reset_tokens) e as sessões de login
-- (sessions). NÃO apaga pedidos: eles ficam guardados pela obrigação fiscal e de defesa do consumidor.
BEGIN;

DELETE FROM customer_password_reset_tokens WHERE email = lower(trim('EMAIL_DA_CONTA'));

DELETE FROM sessions
WHERE user_id IN (SELECT CAST(id AS TEXT) FROM customers WHERE email = lower(trim('EMAIL_DA_CONTA')));

DELETE FROM customers WHERE email = lower(trim('EMAIL_DA_CONTA'));

-- Confira: precisa voltar 0. Se voltar 1, troque o COMMIT por ROLLBACK e revise o e-mail.
SELECT count(*) AS contas_restantes FROM customers WHERE email = lower(trim('EMAIL_DA_CONTA'));

COMMIT;
