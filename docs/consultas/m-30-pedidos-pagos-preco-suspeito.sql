-- M-30: pedidos pagos com algum item de preço unitário suspeito (padrão: acima de R$ 200,00).
-- O bug do painel gravava o preço 100 vezes maior ("5.50" virava R$ 550,00) ou 10 vezes maior
-- ("49.9" virava R$ 499,00). Como escolher o limite: rode antes m-30-precos-produtos-e-tamanhos.sql,
-- pegue a unidade legítima mais cara (maior preço base de peça + maior acréscimo de tamanho que ela usa)
-- e deixe o limite logo acima dela. Assim qualquer erro de 10× ou de 100× passa do limite.
-- O padrão de R$ 200,00 vale para o catálogo de exemplo (peças até R$ 89,90 + acréscimo até R$ 65,00
-- = ~R$ 155,00 a unidade). Para mudar, troque 20000 (centavos) na linha marcada.
-- SOMENTE LEITURA: a transação é READ ONLY e termina em ROLLBACK; nenhum dado é alterado.
-- Não traz CPF, e-mail, telefone nem endereço do cliente.
BEGIN READ ONLY;

SELECT o.order_number                                          AS pedido,
       o.status                                                AS status,
       o.paid_at                                               AS pago_em,
       oi.product_title_snapshot                               AS produto,
       oi.size_name_snapshot                                   AS tamanho,
       oi.quantity                                             AS quantidade,
       oi.unit_price_cents                                     AS unit_price_cents,
       'R$ ' || translate(to_char(oi.unit_price_cents / 100.0, 'FM999,999,990.00'), ',.', '.,') AS preco_unitario_em_reais,
       o.total_cents                                           AS total_cents
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE oi.unit_price_cents > 20000            -- <== limite suspeito, em centavos (R$ 200,00)
  AND (o.paid_at IS NOT NULL
       OR o.status IN ('in_queue', 'printing', 'finishing_and_packaging', 'shipped', 'delivered'))
ORDER BY oi.unit_price_cents DESC, o.paid_at DESC;

ROLLBACK;
