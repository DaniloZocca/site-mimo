# Documentação × código real

Divergências encontradas ao escrever os testes de caracterização. Nada foi corrigido: este arquivo só registra.
"Doc" = `CLAUDE.md` (raiz), `backend/CLAUDE.md` e as skills em `.claude/skills/`.

## Banco de dados

| Doc diz | Código faz | Evidência |
|---|---|---|
| "Banco: SQLite" (`CLAUDE.md`, Arquitetura) | O `.env` de desenvolvimento usa `DB_CONNECTION=pgsql`; as migrations usam `jsonb`. Produção é Postgres. | `backend/.env` (só a chave foi lida); `database/migrations/2026_02_01_000007_create_orders_table.php:22` |
| — | Os testes de caracterização rodam em **SQLite em memória**. Comportamentos que dependem do banco podem diferir no Postgres: diferença entre maiúsculas e minúsculas em `=`/`LIKE`, tipos JSON/jsonb, `lockForUpdate()` (no SQLite não trava nada), mensagens de erro de FK. **Rodar a suíte em Postgres no CI antes de confiar nesses pontos.** | `tests/Feature/Characterization/Support/CharacterizationTestCase.php` |

## Máquina de estados

| Doc diz | Código faz | Evidência |
|---|---|---|
| "Status de pedido só muda via `ProductionWorkflowService::transitionTo()`" (regra 5) e "Webhook, Filament e comandos chamam esse método" (skill `mimoo-order-state-machine`) | **Resolvido no M-24:** checkout, webhook e Filament mudam o status por `ProductionWorkflowService::transitionFrom()` (origem esperada); `transitionTo()` ficou fora do fluxo de produção. Antes: nenhum código de produção chamava o serviço. | `CheckoutController.php:64-68` e `:106-108`; `MercadoPagoWebhookController.php:176-180` e `:208-211`; `OrderResource.php:207-219`, `:237-249`, `:272-285` |
| Painel permite conduzir o pedido até o fim | ✅ Resolvido no M-10 (PR #24): "Cancelar Pedido" (só pedido não pago, fora de "Pagamento em revisão") e "Marcar como Entregue" (só expedido). Pedido já pago não se cancela pelo painel (estorno manual; ver "Decisões pendentes" do `coordenacao.md`). | `FilamentOrderActionsTest::test_acoes_de_linha_existentes_na_tabela`, `FilamentCancelAndDeliverActionsTest` |
| "E-mails/notificações: listeners `ShouldQueue`, disparados após commit" (`backend/CLAUDE.md`) | ✅ Resolvido no M-17: `OrderPaidEvent` implementa `ShouldDispatchAfterCommit`, então o webhook só o dispara depois do commit (o cartão no checkout, fora de transação, dispara na hora). Ainda: com fila `sync`, um erro no envio não desfaz o pagamento, mas o webhook responde 500 (o Mercado Pago reenvia e cai no caminho idempotente). | `app/Events/OrderPaidEvent.php`; `Webhook/WebhookCancellationTest::test_order_paid_event_do_webhook_dispara_depois_do_commit`; `NotificationListenersTest::test_webhook_com_fila_sync_e_falha_no_envio_nao_desfaz_o_pagamento` |

## Pagamento e preço

| Doc diz | Código faz | Evidência |
|---|---|---|
| Pagamento só é aceito se o valor for **igual** a `total_cents` (regra 6 e skill `mimoo-payments`) | ✅ Resolvido em `fix/payment-approval-validator` (bugs #10 e #16): `PaymentApprovalValidator` único no webhook e no cartão síncrono; igualdade exata em centavos, BRL, `external_reference`, payment id e `live_mode` em produção. Divergência → revisão (`pending_payment`), nunca cancelamento. | `app/Services/PaymentApprovalValidator.php` |
| Frete recalculado no servidor (regra 1) | O checkout usa o `shipping_cost_cents` do payload; o `ShippingCalculationService` só é usado em `POST /api/shipping/calculate`. | `CheckoutOrderDTO.php:57`; `OrderCreationService.php:120` |
| Webhook sem segredo deve rejeitar (fail-closed) | ✅ Resolvido em `fix/webhook-fail-closed` (bug #1): sem segredo → 401. | `MercadoPagoWebhookController.php:51` |
| Proibidos atalhos `SIM_`/`TEST_` em produção (regra 10) | ✅ Resolvido em `fix/remove-sim-backdoor` (bug #2): removido de `getPayment()`; `mimoo:simulate-order-flow` usa um cliente HTTP falso do SDK e recusa rodar em produção. | `MercadoPagoService.php` (`getPayment`) |
| Fluxo-alvo: falha do MP após commit → pedido fica `pending_payment`, resposta 502 | ✅ Resolvido no M-08 (bug #8, PR #22): pedido segue `pending_payment`, 502 `payment_gateway_error` com mensagem amigável; detalhe técnico só no log do servidor. Aprovação posterior pelo webhook vai para revisão manual (`checkout_gateway_error`). Ainda falta o job que cancela pendentes expirados (ver "Decisões pendentes" do `coordenacao.md`). | `CheckoutController.php` (catch do gateway) |
| Fluxo-alvo: header `Idempotency-Key` do front evita pedido duplicado; coluna `payment_idempotency_key` | O header é ignorado; não existe a coluna; cada tentativa gera um UUID novo. | `MercadoPagoService.php:44` e `:123` |
| PIX com `date_of_expiration` explícito | Não é enviado. | `MercadoPagoService.php:51-65` |
| Desconto PIX espelhado entre back e front (regra 3, skill `pricing-sync`) | ✅ Resolvido em `fix/remove-free-shipping-display`: front usa `subtotal − Math.floor((subtotal × 10 + 50) / 100)` (só inteiros), igual ao back `subtotal − round(subtotal × 0,10)` (ex.: 4995 → 4495 nos dois). O front também deixou de exibir frete grátis acima de R$ 199 (regra que o back nunca teve). | `src/utils/pricing.js` (`calculateProductPricing`, `calculateCartTotals`); `CheckoutPricingTest::test_arredondamento_do_desconto_pix`; `CheckoutNoFreeShippingTest` |
| `validateWebhookSignature()` no `MercadoPagoService` | Método existe mas o controller não o usa (duplica a lógica inline). Código morto. | `MercadoPagoService.php:213-245`; `MercadoPagoWebhookController.php:65-110` |

## Dados pessoais e logs

| Doc diz | Código faz | Evidência |
|---|---|---|
| CPF, e-mail e telefone nunca vão para logs ou respostas (regra 8) | Listeners e job de notificação não logam mais o e-mail (M-04). Ainda: o `catch` do webhook loga `getMessage()` + trace de qualquer exceção (com fila `sync`, um erro do SMTP pode conter o endereço). | `MercadoPagoWebhookController.php:276` |
| Rastreio por `order_number` + CPF | ✅ Resolvido no M-09: o parâmetro ainda se chama `cpf_prefix` (nome usado pelo front), mas exige o CPF completo (11 dígitos, comparação exata); alternativa: `email` exato. Rota só `POST`. | `TrackOrderRequest.php`; `OrderTrackingController.php` |
| `CheckoutRequest` sanitiza CPF/telefone/CEP | ✅ Resolvido no M-19: a limpeza foi para o `prepareForValidation()`, então as regras validam os dígitos (telefone 10 ou 11, CEP 8) e o `validated()` lido pelo DTO já sai limpo; o pedido grava só dígitos. | `CheckoutRequest.php` (`prepareForValidation`); `Checkout/CheckoutCustomerNormalizationTest` |

## Testes e ferramentas

| Doc diz | Código faz | Evidência |
|---|---|---|
| "`Http::fake()` para Mercado Pago, Melhor Envio e ViaCEP" (`backend/CLAUDE.md`) | O SDK do Mercado Pago usa curl próprio; `Http::fake()` não o intercepta. Os testes usam `MercadoPagoConfig::setHttpClient()` com um cliente falso. ViaCEP não é chamado pelo backend (só pelo front, `src/hooks/useViaCep.js`). | `tests/Feature/Characterization/Support/FakeMercadoPagoHttpClient.php` |
| Mensagens de validação em pt-BR | Resolvido no M-20: `backend/lang/pt_BR` traz as mensagens padrão e os nomes amigáveis dos campos. Resta: a mensagem própria de `items.*.material.enum` nunca é usada (o objeto `Rule\Enum` devolve a padrão, "O valor escolhido em material é inválido."). | `CheckoutRequest.php:145`; `CheckoutValidationTest::test_material_fora_do_enum_retorna_422_do_form_request` |
