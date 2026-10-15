-- M-30: confere se algum preço foi gravado 100 vezes maior pelo painel ("5.50" virava R$ 550,00).
-- SOMENTE LEITURA: a transação é READ ONLY e termina em ROLLBACK; nenhum dado é alterado.
BEGIN READ ONLY;

-- 1) Produtos ativos, do maior para o menor preço base
SELECT p.title                                                AS produto,
       p.base_price_cents                                     AS base_price_cents,
       'R$ ' || translate(to_char(p.base_price_cents / 100.0, 'FM999,999,990.00'), ',.', '.,') AS preco_em_reais,
       p.updated_at                                           AS alterado_em
FROM products p
WHERE p.is_active = true
ORDER BY p.base_price_cents DESC, p.title;

-- 2) Tamanhos (a tabela não tem "ativo"), do maior para o menor acréscimo,
--    com quantos produtos ativos usam cada um
SELECT s.name                                                       AS tamanho,
       s.price_modifier_in_cents                                    AS price_modifier_in_cents,
       'R$ ' || translate(to_char(s.price_modifier_in_cents / 100.0, 'FM999,999,990.00'), ',.', '.,') AS acrescimo_em_reais,
       (SELECT count(*) FROM product_size ps JOIN products p ON p.id = ps.product_id
         WHERE ps.size_id = s.id AND p.is_active = true)            AS produtos_ativos_usando,
       s.updated_at                                                 AS alterado_em
FROM sizes s
ORDER BY s.price_modifier_in_cents DESC, s.name;

ROLLBACK;
