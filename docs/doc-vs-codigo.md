# Documentação × código real

Divergências encontradas ao escrever os testes de caracterização. Nada foi corrigido: este arquivo só registra.
"Doc" = `CLAUDE.md` (raiz), `backend/CLAUDE.md` e as skills em `.claude/skills/`.

## Banco de dados

| Doc diz | Código faz | Evidência |
|---|---|---|
| "Banco: SQLite" (`CLAUDE.md`, Arquitetura) | O `.env` de desenvolvimento usa `DB_CONNECTION=pgsql`; as migrations usam `jsonb`. Produção é Postgres. | `backend/.env` (só a chave foi lida); `database/migrations/2026_02_01_000007_create_orders_table.php:22` |
| — | Os testes de caracterização rodam em **SQLite em memória**. Comportamentos que dependem do banco podem diferir no Postgres: diferença entre maiúsculas e minúsculas em `=`/`LIKE`, tipos JSON/jsonb, `lockForUpdate()` (no SQLite não trava nada), mensagens de erro de FK. **Rodar a suíte em Postgres no CI antes de confiar nesses pontos.** | `tests/Feature/Characterization/Support/CharacterizationTestCase.php` |
| `/api/colors` e `/api/sizes` listam cores/tamanhos ativos | Filtram por `is_active`, coluna que **não existe** em `colors` nem em `sizes`. No SQLite o identificador vira texto literal e a rota devolve sempre `[]` (confirmado); no Postgres deve dar erro 500. | `routes/api.php:34` e `:40`; migrations `2026_02_01_000002` e `000003` |

## Máquina de estados

| Doc diz | Código faz | Evidência |
|---|---|---|
| "Status de pedido só muda via `ProductionWorkflowService::transitionTo()`" (regra 5) e "Webhook, Filament e comandos chamam esse método" (skill `mimoo-order-state-machine`) | **Nenhum código de produção chama o serviço.** Checkout, webhook e Filament alteram `status` direto e gravam o `ProductionLog` por conta própria. Só os testes usam o serviço. | `CheckoutController.php:64-68` e `:106-108`; `MercadoPagoWebhookController.php:176-180` e `:208-211`; `OrderResource.php:207-219`, `:237-249`, `:272-285` |
| Painel permite conduzir o pedido até o fim | Não há ação de cancelar nem de marcar como entregue no painel. `delivered` é inalcançável em produção. | `FilamentOrderActionsTest::test_acoes_de_linha_existentes_na_tabela` |
| "E-mails/notificações: listeners `ShouldQueue`, disparados após commit" (`backend/CLAUDE.md`) | O webhook ainda dispara `OrderPaidEvent` **dentro** do `DB::transaction`, mas desde o M-04 os listeners de e-mail são `ShouldQueueAfterCommit` e só rodam depois do commit. Com fila `sync`, um erro no envio não desfaz o pagamento, mas o webhook responde 500 (o Mercado Pago reenvia e cai no caminho idempotente). | `MercadoPagoWebhookController.php:236`; `NotificationListenersTest::test_webhook_com_fila_sync_e_falha_no_envio_nao_desfaz_o_pagamento` |

## Pagamento e preço

| Doc diz | Código faz | Evidência |
|---|---|---|
| Pagamento só é aceito se o valor for **igual** a `total_cents` (regra 6 e skill `mimoo-payments`) | ✅ Resolvido em `fix/payment-approval-validator` (bugs #10 e #16): `PaymentApprovalValidator` único no webhook e no cartão síncrono; igualdade exata em centavos, BRL, `external_reference`, payment id e `live_mode` em produção. Divergência → revisão (`pending_payment`), nunca cancelamento. | `app/Services/PaymentApprovalValidator.php` |
| Frete recalculado no servidor (regra 1) | O checkout usa o `shipping_cost_cents` do payload; o `ShippingCalculationService` só é usado em `POST /api/shipping/calculate`. | `CheckoutOrderDTO.php:57`; `OrderCreationService.php:120` |
| Webhook sem segredo deve rejeitar (fail-closed) | ✅ Resolvido em `fix/webhook-fail-closed` (bug #1): sem segredo → 401. | `MercadoPagoWebhookController.php:51` |
| Proibidos atalhos `SIM_`/`TEST_` em produção (regra 10) | ✅ Resolvido em `fix/remove-sim-backdoor` (bug #2): removido de `getPayment()`; `mimoo:simulate-order-flow` usa um cliente HTTP falso do SDK e recusa rodar em produção. | `MercadoPagoService.php` (`getPayment`) |
| Fluxo-alvo: falha do MP após commit → pedido fica `pending_payment`, resposta 502 | Pedido vai para `cancelled`, resposta 422 com a mensagem técnica do gateway. | `CheckoutController.php:102-120` |
| Fluxo-alvo: header `Idempotency-Key` do front evita pedido duplicado; coluna `payment_idempotency_key` | O header é ignorado; não existe a coluna; cada tentativa gera um UUID novo. | `MercadoPagoService.php:44` e `:123` |
| PIX com `date_of_expiration` explícito | Não é enviado. | `MercadoPagoService.php:51-65` |
| Desconto PIX espelhado entre back e front (regra 3, skill `pricing-sync`) | ✅ Resolvido em `fix/remove-free-shipping-display`: front usa `subtotal − Math.floor((subtotal × 10 + 50) / 100)` (só inteiros), igual ao back `subtotal − round(subtotal × 0,10)` (ex.: 4995 → 4495 nos dois). O front também deixou de exibir frete grátis acima de R$ 199 (regra que o back nunca teve). | `src/utils/pricing.js` (`calculateProductPricing`, `calculateCartTotals`); `CheckoutPricingTest::test_arredondamento_do_desconto_pix`; `CheckoutNoFreeShippingTest` |
| `validateWebhookSignature()` no `MercadoPagoService` | Método existe mas o controller não o usa (duplica a lógica inline). Código morto. | `MercadoPagoService.php:213-245`; `MercadoPagoWebhookController.php:65-110` |

## Dados pessoais e logs

| Doc diz | Código faz | Evidência |
|---|---|---|
| CPF, e-mail e telefone nunca vão para logs ou respostas (regra 8) | Listeners e job de notificação não logam mais o e-mail (M-04). Ainda: a mensagem técnica do gateway vai para a resposta do checkout e para a timeline pública; o `catch` do webhook loga `getMessage()` + trace de qualquer exceção (com fila `sync`, um erro do SMTP pode conter o endereço). | `CheckoutController.php:118`; `MercadoPagoWebhookController.php:276` |
| Rastreio por `order_number` + CPF | ✅ Resolvido no M-09: o parâmetro ainda se chama `cpf_prefix` (nome usado pelo front), mas exige o CPF completo (11 dígitos, comparação exata); alternativa: `email` exato. Rota só `POST`. | `TrackOrderRequest.php`; `OrderTrackingController.php` |
| `CheckoutRequest` sanitiza CPF/telefone/CEP | A sanitização em `passedValidation()` não chega ao DTO (que lê `validated()`); os valores são gravados com máscara. | `CheckoutRequest.php:161-177`; `CheckoutOrderDTO.php:31` |

## Testes e ferramentas

| Doc diz | Código faz | Evidência |
|---|---|---|
| "`Http::fake()` para Mercado Pago, Melhor Envio e ViaCEP" (`backend/CLAUDE.md`) | O SDK do Mercado Pago usa curl próprio; `Http::fake()` não o intercepta. Os testes usam `MercadoPagoConfig::setHttpClient()` com um cliente falso. ViaCEP não é chamado pelo backend (só pelo front, `src/hooks/useViaCep.js`). | `tests/Feature/Characterization/Support/FakeMercadoPagoHttpClient.php` |
| Mensagens de validação em pt-BR | Resolvido no M-20: `backend/lang/pt_BR` traz as mensagens padrão e os nomes amigáveis dos campos. Resta: a mensagem própria de `items.*.material.enum` nunca é usada (o objeto `Rule\Enum` devolve a padrão, "O valor escolhido em material é inválido."). | `CheckoutRequest.php:145`; `CheckoutValidationTest::test_material_fora_do_enum_retorna_422_do_form_request` |
