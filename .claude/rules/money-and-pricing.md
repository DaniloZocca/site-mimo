---
paths:
  - "src/utils/pricing.js"
  - "src/context/CartContext.jsx"
  - "src/components/checkout/**"
  - "backend/app/Services/OrderCreationService.php"
  - "backend/app/Services/ShippingCalculationService.php"
  - "backend/app/DTOs/**"
  - "backend/app/Http/Requests/CheckoutRequest.php"
---
# Dinheiro
- Centavos inteiros sempre; arredondamento com `Math.round`/`intdiv` explícito e testado.
- O servidor recalcula tudo; o front só estima. Qualquer campo `*_cents` recebido do cliente é ignorado para cobrança.
- Mudou regra de preço, desconto ou frete: use a skill `pricing-sync` e escreva teste com payload adulterado.
