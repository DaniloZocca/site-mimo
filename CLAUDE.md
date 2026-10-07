# MIMOO LAB 3D Studio — site-mimo

Loja brasileira de colecionáveis impressos em 3D sob encomenda (FDM). Só imprimimos após pagamento confirmado.
Monorepo: frontend React 19 + Vite (raiz, `src/`) + API Laravel 11 + Filament 3 (`backend/`). UI, mensagens e commits em pt-BR.
Antes de tarefas de segurança/pagamento, leia `RELATORIO_SEGURANCA_MIMOO.md` (achados abertos C1–B3) e `docs/known-bugs.md` (bugs confirmados por teste, com severidade).
As regras abaixo descrevem o comportamento-alvo; onde o código ainda diverge, está registrado em `docs/doc-vs-codigo.md`.

## Comandos
- Front: `npm run dev` | `npm run lint` (oxlint) | `npm run build`. Ainda não há testes de front (Vitest não instalado).
- Back (dentro de `backend/`): `php artisan serve` (porta 8000) | `php artisan test` (PHPUnit) | `vendor/bin/pint --dirty`
- O backend está 100% formatado pelo Pint; `vendor/bin/pint --test` deve passar em todo PR. Edite PHP normalmente pelas ferramentas de edição (o hook formata só o arquivo editado).
- Testes de caracterização: `php artisan test --group=characterization` (deve ficar 100% verde) e `php artisan test --group=known-bugs` (falha de propósito; um gêmeo por bug em `docs/known-bugs.md`; excluído da suíte padrão). Exigem a extensão `pdo_sqlite` no PHP.
- Rode o teste mais específico (`php artisan test --filter=NomeDoTeste`); a suíte completa só ao final da tarefa.
- Larastan/PHPStan e Pest não estão instalados: não os invoque sem pedir para instalar.

## Antes de começar: classifique a tarefa
Escreva uma linha: `Tamanho: pequeno | médio | grande — motivo`.
- Pequeno (1 arquivo, sem regra de negócio): faça direto e verifique.
- Médio (2+ arquivos ou toca dinheiro/status/dados pessoais): apresente plano curto e aguarde aprovação.
- Grande (feature nova, mudança de schema, upgrade de framework): use a skill `new-feature` (spec antes de código).
Na dúvida entre dois tamanhos, escolha o menor — exceto se tocar pagamento, que é sempre médio no mínimo.

## Regras invioláveis (motivo entre parênteses)
1. Zero-trust pricing: preço de peças E frete são recalculados no servidor (`OrderCreationService` / `ShippingCalculationService`). Valores monetários do request são só exibição (o payload é controlado pelo usuário).
2. Dinheiro sempre em centavos inteiros (`*_cents`), nunca float — nem no JS. Conversão para reais só na borda do Mercado Pago (`transaction_amount`).
3. Desconto PIX de 10% incide só no subtotal das peças, nunca no frete. Backend é a fonte da verdade; `src/utils/pricing.js` espelha só para exibição — mudou um, atualize o outro (skill `pricing-sync`). Os dois fazem `subtotal − round(subtotal × 0,10)` (no front só com inteiros: `Math.floor((subtotal × 10 + 50) / 100)`). Não há frete grátis: o frete exibido e cobrado é sempre o da cotação; se um dia houver, a regra nasce no backend.
4. Pedido é commitado ANTES de chamar o Mercado Pago. Nenhuma chamada HTTP externa dentro de `DB::transaction`.
5. Status de pedido só muda via `ProductionWorkflowService::transitionTo()` (mapa `ALLOWED_TRANSITIONS`), gravando `ProductionLog`.
6. Pagamento só é aceito após `MercadoPagoService::getPayment()` confirmar `approved` + valor igual a `total_cents` + `external_reference`. Webhook: assinatura válida (fail-closed), evento idempotente, resposta 200 rápida.
7. Rastreio anti-IDOR: `order_number` + CPF, ou `tracking_token`. Nunca exponha dados pessoais sem máscara.
8. CPF, e-mail, telefone, tokens e segredos nunca vão para logs, exceções ou respostas.
9. `OrderItem` é snapshot imutável: não recalcule pedidos antigos a partir de `Product`.
10. Nada de atalhos de teste em código de produção (ex.: IDs `SIM_`/`TEST_` que simulam pagamento aprovado). Simulação vive em testes com `Http::fake()`/mocks.

## Como trabalhar neste repo
- Leia os arquivos envolvidos e o padrão existente mais próximo antes de editar.
- Um PR por bug, sempre com base na `main`, nunca empilhado. Não faça push na `main`.
- Faça o menor diff que resolve a tarefa. Não refatore, renomeie ou reformate fora do escopo; liste outros problemas no final.
- Não adicione dependências (npm/composer) sem pedir.
- Não altere nem remova testes existentes para fazê-los passar; não hard-code valores esperados. Se um teste parecer errado, explique e pergunte.
- Toda mudança de regra de negócio vem com teste PHPUnit em `backend/tests/Feature` (modelo: `CheckoutHardeningTest.php`).
- Contas (totais, descontos, cubagem, datas) se verificam com código ou teste, não de cabeça.
- Migrations existentes não são editadas: crie uma nova (skill `mimoo-migrations-uuid`).
- Frontend segue o design system Kawaii Chunky (skill `kawaii-chunky-ui`).
- Se a instrução for ambígua ou contradizer estas regras, pare e pergunte em vez de escolher sozinho.

## Ao terminar, reporte
- `Status: PRONTO | PRONTO COM RESSALVAS | BLOQUEADO`
- Arquivos alterados, comandos rodados e a saída relevante (evidência, não "funcionou").
- Problemas vistos fora do escopo (sem corrigi-los).

## Arquitetura (o que não é óbvio lendo o código)
- Navegação por abas via `ShopContext` (sem router). Catálogo cai para `src/data/mockProducts.js` se a API falhar — nunca use mock para calcular preço no checkout.
- Componentes duplicados: existem `src/components/ProductCard.jsx` e `src/components/catalog/ProductCard.jsx` (idem `CartDrawer`, `ProductDetailModal`). `App.jsx`/`ProductGrid.jsx` importam as versões da raiz de `components/`. Confirme qual é importada antes de editar.
- Carrinho em localStorage (`mimoo_cart_v2` em `CartContext.jsx`).
- `PackagingService`: tara caixa 50 g + plástico bolha 30 g; mínimo Correios 16×11×6 cm.
- Frete: Melhor Envio com cache 6 h (MD5 de CEP+cubagem), fallback regional, soma o lead time de impressão.
- Contrato de frete no `POST /api/checkout`: o front envia `shipping_service` (o `service_code` devolvido por `/api/shipping/calculate`, ex.: `pac`/`sedex`) e, opcionalmente, `shipping_cost_cents` (valor exibido). O servidor recalcula com o `ShippingCalculationService` (mesmo cache): serviço indisponível → 422 em `errors.shipping_service`; valor exibido diferente → 409 `{"code":"shipping_price_changed","message","shipping_service","shipping_cost_cents","shipping_options":[...]}` sem criar pedido. O pedido grava o snapshot `shipping_service_code/name`, `shipping_carrier_name`, `shipping_delivery_days` e `shipping_price_source` (`melhor_envio` | `fallback`).
- Idempotência do `POST /api/checkout`: header `Idempotency-Key` (UUID) obrigatório — ausente/inválido → 400 `idempotency_key_required`. `orders.idempotency_key` é UNIQUE (a garantia contra duplicata; não use `lockForUpdate`) e `request_fingerprint` é o SHA-256 do payload canônico sem `card_token`. Mesma chave + mesmo payload → replay da primeira resposta (mesmo status HTTP e corpo, header `Idempotent-Replayed: true`, montada de `order_payments`) sem chamar o gateway; payload diferente → 422 `idempotency_key_reused`; primeiro envio ainda sem resposta → 409 `request_in_progress` (`Retry-After: 2`). O `X-Idempotency-Key` do Mercado Pago é `orders.payment_idempotency_key`, o mesmo em toda tentativa de pagamento do pedido. No front, a chave vem de `useCheckoutAttemptKey` e o envio de `postCheckout` (`src/utils/checkoutRequest.js`).
- Aprovação de pagamento: `PaymentApprovalValidator` é o único ponto de decisão (webhook e cartão síncrono) — libera `pending_payment → in_queue` só com `approved`, centavos == `total_cents`, BRL, `external_reference` e payment id de `order_payments`, e `live_mode` em produção.
- Cartão aprovado com divergência → 202 `{"code":"payment_under_review","message","order_number","tracking_token","status"}`: pedido segue `pending_payment`, sem nova cobrança; replay com a mesma `Idempotency-Key` devolve o mesmo 202.
- Webhook responde 200 em divergência (revisão humana, não reenvio) e em status não aprovado; aprovado para pedido cancelado → alerta + registro "verificar estorno", sem reabrir.
- `production_logs.is_internal/code/reference`: registros internos (`payment_review`, `payment_after_cancellation`) com dedupe por `(order_id, code, reference)`; o rastreio carrega só `productionLogs()->public()` na query.
- Banco: **PostgreSQL** em desenvolvimento e produção (`DB_CONNECTION=pgsql`, colunas `jsonb`). Os testes antigos (`DatabaseTransactions`) rodam contra esse Postgres; os de caracterização rodam em SQLite `:memory:` com trava de segurança (`tests/Feature/Characterization/Support/CharacterizationTestCase.php`). Diferenças entre os dois bancos (maiúsculas/minúsculas, JSON, locks, colunas inexistentes) só aparecem no Postgres — CI em Postgres ainda pendente.
- Concorrência: nos testes em SQLite `lockForUpdate()` não trava nada; prefira update condicional (`WHERE status = ?`, 0 linhas = conflito), que funciona nos dois bancos.
- Nenhum caminho de produção usa o `ProductionWorkflowService` hoje: `CheckoutController`, `MercadoPagoWebhookController` e as ações do Filament (`OrderResource`) alteram `status` direto (dívida conhecida; ao mexer em um deles, migre para o serviço).
- Ações de status do painel: produção (`startPrinting`, `startPackaging`, `markAsShipped`) só `in_queue → printing → finishing_and_packaging → shipped`; `markAsDelivered` só `shipped → delivered`; `cancelOrder` só `pending_payment → cancelled`, com motivo obrigatório, escondido e recusado no UPDATE para pedido em "Pagamento em revisão" (pedido pago não se cancela pelo painel: estorno manual). Iniciar impressão e expedir disparam `OrderPrintingStartedEvent`/`OrderShippedEvent` (e-mails pela fila); acabamento, cancelar e entregar não mandam e-mail. A visibilidade usa `ProductionWorkflowService::allows()` (consulta ao `ALLOWED_TRANSITIONS`). A gravação é um update condicional (`WHERE status = <origem>`) em `OrderResource::applyPanelTransition()`: com 0 linhas não grava log nem dispara evento e mostra a notificação de perigo "O status deste pedido mudou. Atualize a página."; o código de rastreio entra no mesmo UPDATE. O log grava `code=status_change` e `is_internal=false`. Nenhum caminho do painel libera um pedido de `pending_payment` para a produção; o único que sai de lá é o cancelar (o campo `status` do formulário é `disabled`, não desidratado). Pedido em "Pagamento em revisão" legítimo: `docs/runbook-liberar-pedido-em-revisao.md`.
- Mercado Pago nos testes: o SDK usa curl próprio, então `Http::fake()` NÃO o intercepta. Use `FakeMercadoPagoHttpClient` (registrado via `MercadoPagoConfig::setHttpClient()` na `CharacterizationTestCase`).
- Eventos: existem pares duplicados (`OrderPaid`/`OrderPaidEvent`, `OrderShipped`/`OrderShippedEvent`, `OrderPrintStarted`/`OrderPrintingStartedEvent`); só as versões `*Event` têm listener. Os listeners são registrados no `AppServiceProvider` E pela descoberta automática do Laravel 11 (registro em dobro).

## Compactação
Ao compactar, preserve: arquivos modificados, comandos de teste usados, decisões sobre dinheiro/status e achados de segurança em aberto.
