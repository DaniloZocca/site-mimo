-- M-38: pedidos gravados com o e-mail da usuária de demonstração "Fernanda" (fernanda@mimoolab.com.br).
-- Até o M-38 o checkout abria com nome e e-mail da Fernanda já preenchidos para todo visitante; quem não
-- apagou fez o pedido com esse e-mail e não recebeu a confirmação nem os avisos de produção e envio.
-- Use a lista para avisar esses clientes (pelo telefone completo no painel, abrindo o pedido pelo número).
-- Pode rodar ANTES ou DEPOIS do deploy: só lê colunas que já existem.
-- SOMENTE LEITURA: a transação é READ ONLY e termina em ROLLBACK; nenhum dado é alterado.
-- O telefone sai mascarado (DDD e os 2 últimos dígitos); não traz CPF, e-mail nem endereço.
BEGIN READ ONLY;

-- 1) Pedidos com o e-mail da Fernanda (maiúsculas, minúsculas e espaços nas pontas ignorados), mais recentes primeiro
WITH fone AS (
    SELECT o.id,
           regexp_replace(o.customer_phone, '\D', '', 'g') AS digitos
    FROM orders o
)
SELECT o.order_number                                          AS pedido,
       o.created_at                                            AS criado_em,
       o.status                                                AS status,
       o.customer_name                                         AS nome,
       CASE
           WHEN length(f.digitos) >= 6
               THEN '(' || substr(f.digitos, 1, 2) || ') ' || repeat('*', length(f.digitos) - 4) || right(f.digitos, 2)
           ELSE '***'
       END                                                     AS telefone_mascarado
FROM orders o
JOIN fone f ON f.id = o.id
WHERE lower(trim(o.customer_email)) = 'fernanda@mimoolab.com.br'
ORDER BY o.created_at DESC;

-- 2) Contagem: total e por status
SELECT o.status                                                AS status,
       count(*)                                                AS pedidos
FROM orders o
WHERE lower(trim(o.customer_email)) = 'fernanda@mimoolab.com.br'
GROUP BY ROLLUP (o.status)
ORDER BY o.status NULLS LAST;
-- A linha com status vazio (NULL) é o total.

ROLLBACK;
