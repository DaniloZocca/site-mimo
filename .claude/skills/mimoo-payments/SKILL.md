---
name: mimoo-payments
description: Regras de checkout, pagamento PIX/cartão e webhook do Mercado Pago na MIMOO. Use SEMPRE que a tarefa tocar CheckoutController, CheckoutRequest, CheckoutOrderDTO, OrderCreationService, MercadoPagoService, MercadoPagoWebhookController, CheckoutModal, CreditCardPaymentStep, useMercadoPagoCard, usePaymentStatus, totais, frete cobrado, desconto PIX, reembolso ou status de pagamento — mesmo que o pedido não diga "pagamento".
---
# Checkout e pagamentos (Mercado Pago)

## Fluxo-alvo
1. `POST /api/checkout` recebe itens (product_id, color_id, size_id, material, quantity), CEP + serviço de frete escolhido, payment_method, card_token (cartão) e header `Idempotency-Key` gerado no front por tentativa.
2. Mesma Idempotency-Key já usada → devolva a resposta do pedido existente, sem criar outro.
3. Em transação: valide que cor e tamanho pertencem ao produto e estão ativos (422), material em `allowed_materials` (422); recalcule preço (base + modificador de tamanho) e frete no servidor; 10% PIX só nas peças; grave Order + OrderItems (snapshots) em `pending_payment` com `payment_idempotency_key`. Commit.
4. Fora da transação: crie o pagamento com header `X-Idempotency-Key` = `payment_idempotency_key`, `external_reference` = número do pedido e, no PIX, `date_of_expiration` explícito.
5. Falha do MP após o commit: pedido fica `pending_payment`, resposta 502 amigável; job agendado cancela expirados.

## Webhook
- Sem `MERCADOPAGO_WEBHOOK_SECRET` configurado → rejeite (fail-closed), nunca aceite.
- Manifesto `id:{data.id};request-id:{x-request-id};ts:{ts};` (data.id alfanumérico em minúsculas), HMAC-SHA256 hex, compare com `hash_equals`. Inválido → 401. Rejeite `ts` muito antigo.
- Registre o evento de forma idempotente (unique por provedor + id + ação); duplicado → 200 sem reprocessar.
- Responda 200 rápido (MP espera até 22 s e reenvia a cada 15 min); processamento pesado em job.
- Confirme via `getPayment()`: só avance para `in_queue` se `status=approved`, `transaction_amount*100 == total_cents` e `external_reference` confere. Transição via `ProductionWorkflowService::transitionTo()`.
- `rejected`/`cancelled` → `cancelled`. `refunded`/`charged_back` depois de pago → log + alerta ao operador (estado ainda não existe no enum: pergunte antes de criar).

## Proibido
- Atalhos em produção que devolvem `approved` para IDs `SIM_`/`TEST_`. Simulação só em teste.
- Logar payload completo, CPF, e-mail, card_token ou access token.

## Testes obrigatórios ao mexer aqui (PHPUnit, `Http::fake()`)
Preço e frete adulterados no payload; cor/tamanho de outro produto; PIX sem desconto no frete; duplo envio com a mesma Idempotency-Key; webhook sem segredo, com assinatura inválida e duplicado; valor divergente no `getPayment()`.
