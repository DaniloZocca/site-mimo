-- M-29: peças que ficam sem preço (preço base + acréscimo do tamanho abaixo de R$ 0,01).
-- Depois do M-29 o checkout recusa essas combinações e o catálogo esconde o tamanho; corrija no painel
-- (aumente o preço base, diminua o desconto do tamanho ou desmarque o tamanho da peça).
-- Pode rodar ANTES do deploy: só lê tabelas e colunas que já existem, não depende do código novo.
-- SOMENTE LEITURA: a transação é READ ONLY e termina em ROLLBACK; nenhum dado é alterado.
-- Não traz CPF, e-mail, telefone nem endereço do cliente.
BEGIN READ ONLY;

-- 1) Combinações peça + tamanho abaixo de R$ 0,01 (ativas primeiro)
SELECT p.title                                                AS produto,
       p.is_active                                            AS ativo,
       s.name                                                 AS tamanho,
       p.base_price_cents                                     AS base_price_cents,
       s.price_modifier_in_cents                              AS price_modifier_in_cents,
       p.base_price_cents + s.price_modifier_in_cents         AS unitario_cents,
       'R$ ' || translate(to_char((p.base_price_cents + s.price_modifier_in_cents) / 100.0, 'FM999,999,990.00'), ',.', '.,') AS unitario_em_reais
FROM products p
JOIN product_size ps ON ps.product_id = p.id
JOIN sizes s ON s.id = ps.size_id
WHERE p.base_price_cents + s.price_modifier_in_cents < 1
ORDER BY p.is_active DESC, unitario_cents, p.title, s.name;

-- 2) Peças sem tamanho ligado com preço base abaixo de R$ 0,01
SELECT p.title                                                AS produto,
       p.is_active                                            AS ativo,
       p.base_price_cents                                     AS base_price_cents
FROM products p
WHERE p.base_price_cents < 1
  AND NOT EXISTS (SELECT 1 FROM product_size ps WHERE ps.product_id = p.id)
ORDER BY p.is_active DESC, p.title;

-- 3) Pedidos já gravados com algum item de preço zero ou negativo (não são recalculados: decida caso a caso)
SELECT o.order_number                                          AS pedido,
       o.status                                                AS status,
       o.created_at                                            AS criado_em,
       oi.product_title_snapshot                               AS produto,
       oi.size_name_snapshot                                   AS tamanho,
       oi.quantity                                             AS quantidade,
       oi.unit_price_cents                                     AS unit_price_cents,
       o.total_cents                                           AS total_cents
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE oi.unit_price_cents < 1
ORDER BY o.created_at DESC;

ROLLBACK;
