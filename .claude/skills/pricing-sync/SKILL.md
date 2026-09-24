---
name: pricing-sync
description: Mantém a regra de preço sincronizada entre backend (OrderCreationService, ShippingCalculationService) e frontend (src/utils/pricing.js, CartContext). Use ao mudar preço base, modificador de tamanho, desconto PIX, parcelamento, frete, arredondamento ou qualquer total exibido.
---
# Sincronizar preço front/back
1. Leia a regra no backend primeiro (`OrderCreationService`) — é a fonte da verdade.
2. Escreva/atualize o teste PHPUnit com números concretos em centavos (inclua um caso de arredondamento, ex.: 3333 com 10% PIX).
3. Só depois ajuste `src/utils/pricing.js` para produzir exatamente os mesmos números.
4. Confirme a paridade rodando os mesmos casos nos dois lados (script `node -e` importando `pricing.js`, e `php artisan test --filter=...`). Não calcule de cabeça.
5. Mostre a tabela entrada → resultado back → resultado front.
