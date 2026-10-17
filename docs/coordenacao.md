# Coordenação — backlog e protocolo

Fonte única da ordem de trabalho do projeto. Antes de qualquer tarefa, leia este arquivo e o `CLAUDE.md`.
Fontes do backlog: `docs/known-bugs.md` (bugs #N), `RELATORIO_SEGURANCA_MIMOO.md` (achados C1–B4),
`docs/doc-vs-codigo.md` e o roteiro combinado com o Zocca em 2026-10-07.

## Protocolo

### Uma tarefa
- Uma tarefa `M-XX` = uma thread = uma branch criada a partir de `origin/main` atualizada = um PR.
- Título da thread e do PR começam com `[M-XX]`. Mensagem que não é da tarefa da thread: responder
  "Esta thread é [M-XX]; mande no chat do projeto" e não fazer nada.
- Nunca push na `main`, nunca `--force`, nunca PR empilhado. Deploy é sempre do humano.
- PR aberto como rascunho cedo; commit + push do trabalho em andamento com frequência.
- Classe **C** ou **D**: explorar, escrever o plano e **parar para aprovação do Zocca** antes de implementar.
- Antes de dizer que terminou: colar no PR a saída real (linhas de resumo) da suíte de testes,
  `vendor/bin/pint --test`, `npm run lint` e `npm run build`. A cada push, reescrever a descrição do PR.
- PR que toca `src/`: screenshots antes/depois (375px e 1280px) e revisão do subagente `ui-reviewer`
  ("o visual ficou idêntico ao padrão Kawaii Chunky").
- Merge (decisão do Zocca em 2026-10-07): o Claude pode mergear quando o PR estiver pronto (CI verde no
  último commit, sem conflito, comentários resolvidos, saídas coladas). "CI verde" = os checks do workflow
  `CI` (`.github/workflows/ci.yml`) verdes no último commit do PR, atualizado com a `main`.
  **Suspenso (Zocca, 2026-10-08):** até o Zocca ativar a proteção da `main` com os checks obrigatórios,
  nenhum merge é do Claude: abrir o PR, deixar pronto e avisar na thread. Depois do merge, avisar na thread
  o que entrou e o que fazer antes do deploy.
- O PR da tarefa atualiza a própria linha da tabela abaixo (status e PR) e, se corrigir bug, `docs/known-bugs.md`.
  Conflito nesses dois arquivos se resolve mergeando a `main` e mantendo as duas linhas.

### Classes
| Classe | Quando | Aprovação |
|---|---|---|
| A | Só documentação ou texto interno, sem lógica | Não precisa |
| B | Correção ou ajuste sem dinheiro, pagamento, contrato de API, schema ou dado pessoal | Não precisa |
| C | Toca dinheiro, pagamento, status de pedido, contrato de API (formato/código de resposta) ou dado pessoal | Plano + OK do Zocca |
| D | Migration/schema, upgrade de stack ou infraestrutura (CI, dependências) | Plano + OK do Zocca |

Na dúvida entre duas classes, vale a mais alta.

### Coordenador
- No máximo **2 threads** ao mesmo tempo, e **nunca 2 na mesma zona**.
- Tarefa com mais de uma zona ocupa todas elas enquanto estiver aberta.
- Tarefas da zona **stack** rodam sozinhas (nenhuma outra thread aberta).
- Seguir a ordem da tabela: pegar a primeira tarefa "a fazer" cujas dependências estão concluídas e cujas
  zonas estão livres. Tarefa C/D: propor a thread com o plano e esperar o OK; enquanto espera, pode pegar a
  próxima tarefa A/B com zona livre.
- Decisão de negócio que aparecer no meio de uma tarefa: manter o comportamento atual ou o mais conservador,
  registrar em "Decisões pendentes" e seguir.

### Zonas
Duas tarefas de zonas diferentes não editam os mesmos arquivos. Se uma tarefa precisar de arquivo de outra
zona, declara essa zona também.

| Zona | Arquivos (backend em `backend/`) |
|---|---|
| checkout | `Http/Controllers/Api/CheckoutController.php`, `MercadoPagoWebhookController.php`, `Http/Requests/CheckoutRequest.php`, `DTOs/` (checkout), `Services/OrderCreationService.php`, `MercadoPagoService.php`, `PaymentApprovalValidator.php`, `ShippingCalculationService.php`, `PackagingService.php`; testes `Checkout/`, `Webhook/`, `Payment/` |
| pedidos | `Services/ProductionWorkflowService.php`, `Events/`, `Listeners/`, `Jobs/`, `Mail/`, `resources/views/emails/`, `Providers/AppServiceProvider.php`; testes `OrderStateMachine/` (exceto Filament) |
| rastreio | `Http/Controllers/Api/OrderTrackingController.php`, `Http/Requests/TrackOrderRequest.php`, `Http/Resources/OrderTrackingResource.php`; testes `Tracking/` |
| painel | `app/Filament/`; testes `tests/Feature/Filament/` |
| rotas | `routes/api.php`, `bootstrap/app.php`, `Http/Middleware/` |
| config | `config/`, `.env.example`, `lang/`, `database/seeders/` |
| front | `src/`, `index.html`, `public/` |
| stack | `composer.*`, `package*.json`, `vite.config.*`, `tailwind.config.*`, `.github/`, upgrades de framework |

Arquivos de todos (`docs/coordenacao.md`, `docs/known-bugs.md`, `docs/doc-vs-codigo.md`) não ocupam zona;
cada PR mexe só nas linhas da sua tarefa.

## Backlog

Status: `concluído` · `em merge` (PR aberto, pronto) · `a fazer` · `aguardando plano` (C/D esperando OK) ·
`em andamento` · `bloqueado` · `cancelado`.

| # | Tarefa | Classe | Zonas | Depende de | Status | PR |
|---|---|---|---|---|---|---|
| M-00 | Criar este arquivo de coordenação | A | — | — | concluído | #13 |
| M-01 | Trocar a senha do admin e tirar e-mail/senha padrão do `AdminUserSeeder` (C4) | B | config | — | concluído (senha local já trocada; servidor publicado precisa trocar à parte) | #11 |
| M-02 | Tirar a promessa de frete grátis da loja | B | front | — | concluído | #10 |
| M-03 | Validar cor, tamanho e material do próprio produto no checkout (bugs #5, #6, #19) | C | checkout, front | — | concluído | #12 |
| M-04 | E-mails ao cliente: listeners com relações certas (#13), confirmação registrada uma vez só (#23), e-mail fora dos logs dos listeners/job (doc-vs-código) | C | pedidos | — | concluído (também #26: valores do e-mail de confirmação, com linha "Desconto PIX" só quando há desconto; listeners de e-mail só depois do commit) | #18 |
| M-05 | Config de produção: tirar `'*'` do CORS (A4), `SESSION_SECURE_COOKIE` no `.env.example` (M3), checagem que falha com `APP_DEBUG=true` em produção (C3) | B | config (+ `app/Console/Commands/`, sem zona) | — | concluído (o `.env.example` é bloqueado para o Claude: o Zocca acrescenta `SESSION_SECURE_COOKIE` e `CORS_ALLOWED_ORIGINS` à mão; em produção o cookie já é Secure por padrão) | #14 |
| M-06 | SDK do Mercado Pago em `SERVER` fora do ambiente local (M2) | C | checkout | — | a fazer | — |
| M-07 | Front: trocar `localhost:8000` fixo por `import.meta.env.VITE_API_URL` em `ShopContext`, `CheckoutModal`, `useMercadoPagoCard`, `useShipping`, `trackingService` (B2) | B | front | M-02 | concluído (também `usePaymentStatus`, que usava `/api` relativo; base em `src/config/api.js`) | #15 |
| M-08 | Erro do gateway mantém pedido pendente com 502 genérico (#8); cartão recusado responde 422 amigável (#9); cartão aprovado dispara `OrderPaidEvent` (#20); mensagens no `CheckoutModal` | C | checkout, front | M-03, M-04 | concluído (recusa fica `pending_payment` até o webhook cancelar; aprovação depois de falha do gateway vai para revisão) | #22 |
| M-09 | Privacidade do rastreio: CPF completo em vez de prefixo (#11), timeline sem notas internas (#12), só POST (#17) | C | rastreio, rotas | — | concluído (o texto do formulário da loja fica no M-28) | #23 |
| M-10 | Painel: ações de cancelar e de marcar como entregue; ações de produção disparam os eventos com listener (#21) | C | painel, pedidos | M-04 | concluído (cancelar só pedido não pago e fora de "Pagamento em revisão", com motivo; entregar só expedido; iniciar impressão e expedir mandam e-mail) | #24 |
| M-11 | `/api/colors` e `/api/sizes` filtram por `is_active`, coluna que não existe (500 no Postgres) | B | rotas | M-09 | concluído (listam todas as cores/tamanhos por nome, sem migration; a cor traz `in_stock`) | #26 |
| M-12 | Simplificar a tela de materiais e cores no painel | B | painel | M-10 | concluído (cores da peça mostram material e "esgotada", com busca e marcar todas; salvar peça exige cor só de material homologado e pelo menos uma cor por material; cor usada em peça não se apaga; tirado o filtro "Status" de Tamanhos, que usava a coluna inexistente `is_active`) | #31 |
| M-13 | Compra rápida do card do catálogo escolhe cor compatível com o material (hoje pode cair no 422 do M-03) | B | front | M-03 | concluído (sem cor compatível em nenhum material, o botão abre os detalhes do produto) | #16 |
| M-14 | Higiene: limpeza de hooks do `ProductDetailModal` (13 erros `rules-of-hooks` do oxlint hoje; sem mudança visual) | B | front | M-03 | concluído (oxlint sem erros em `src/`; o conteúdo do modal remonta a cada produto aberto, como já acontecia na prática) | #17 |
| M-15 | Rate limit separado para checkout, rastreio e frete (#18) | B | rotas | M-11 | concluído (contador próprio por grupo de rotas; limites 10/10/30 por minuto mantidos) | #27 |
| M-16 | `transitionTo()` valida contra o status do banco (#15); `dispatchOrder()` só grava o código após validar (#22) | C | pedidos | M-04, M-10 | concluído (também: corrida com o mesmo destino é no-op, a instância recebida reflete o banco, eventos só após o commit externo; nenhum caminho de produção usa o serviço até o M-24) | #33 |
| M-17 | Webhook: cancelamento confere o payment id em `order_payments` e usa update condicional (#24); `OrderPaidEvent` só após o commit | C | checkout | M-08 | concluído (recusa de outro pagamento é ignorada com 200 + log; sem id gravado cancela como antes; `OrderPaidEvent` com `ShouldDispatchAfterCommit`; migração para o `ProductionWorkflowService` fica no M-24) | #32 |
| M-18 | Desconto PIX igual no front e no back (diferença de 1 centavo; skill `pricing-sync`) | C | checkout, front | M-08 | cancelado (duplicado do PR #10, conferido na `main` em 2026-10-07: o back faz `subtotal − round(subtotal × 0,10)` em `OrderCreationService.php:206-210` e o front usa a mesma conta só com inteiros em `src/utils/pricing.js:117` (`calculateProductPricing`) e `:186` (`calculateCartTotals`); fuzz de 900 mil comparações com 0 diferença; `docs/doc-vs-codigo.md:32` já marca resolvido e `docs/known-bugs.md` não tem a entrada) | #10 |
| M-19 | Sanitização de CPF/telefone/CEP do `CheckoutRequest` chegar ao DTO | C | checkout | M-17 | concluído (limpeza antes da validação: o pedido grava CPF, telefone e CEP só com dígitos; telefone exige 10 ou 11 dígitos e CEP 8, como no formulário da loja; pedidos antigos não mudam) | #34 |
| M-30 | **P1 · próxima tarefa da zona painel, logo depois do M-19.** Campos de dinheiro do painel (`ProductResource.php:95` preço base, `SizeResource.php:48` acréscimo do tamanho) leem o ponto como separador de milhar: digitar `5.50` grava R$ 550,00; a conversão passa por float (`(float) … * 100`), contra a regra 2. O plano precisa trazer (pedido do Zocca, 2026-10-15): aceitar vírgula **e** ponto como separador decimal (`5,50` e `5.50` → 550 centavos) com conversão só por inteiros, sem float; teste com `5,50`, `5.50`, `1.234,56`, `1234,56`, `49,9` e `0,01`; uma consulta SQL **somente leitura** para o Zocca rodar em produção listando produtos ativos (nome, `base_price_cents`) e tamanhos (nome, `price_modifier_in_cents`), do maior para o menor valor; outra listando pedidos pagos com algum `unit_price_cents` acima de um limite suspeito proposto no plano. Achado no M-12 | C | painel | M-19 | concluído (vírgula e ponto como decimal, conversão só com inteiros, valor ambíguo dá erro no campo; consultas em `docs/consultas/m-30-*.sql`) | #36 |
| M-31 | **P3.** `ShippingCalculationService.php:53` escreve o CEP de destino completo no `Log::info`: logar só os 5 primeiros dígitos (ex.: `01001-***`). Achado no M-19 | B | checkout | M-19 | concluído (o log mostra só `01001-***`) | #35 |
| M-20 | Mensagens de validação em pt-BR (`lang/pt_BR`) | B | config | M-05 | concluído (locale padrão `pt_BR` no config; se o `.env` do servidor tiver `APP_LOCALE=en`, trocar para `pt_BR`) | #19 |
| M-21 | Catálogo: trocar o polling de 3 s do `ShopContext` por algo mais leve, sem mudar a tela | B | front | M-07 | concluído (busca ao abrir, a cada 60 s só com a aba visível e ao voltar para a aba, no mínimo 15 s entre buscas; mudança no painel aparece na loja em até 60 s) | #20 |
| M-22 | CI no GitHub Actions com Postgres (suíte, `pint --test`, lint, build); `oxlint` como devDependency (hoje `npm run lint` falha com "oxlint: not found") e lint sem varrer `backend/public/` | D | stack | M-01 a M-03 mergeados | concluído (workflow `CI`: backend em PHP 8.2 e 8.3 com Postgres 16, frontend com lint com teto de 75 avisos e build) | #30 |
| M-23 | `composer audit` e `npm audit` e correção do que aparecer | D | stack | M-22 | concluído (backend: Laravel 11 → 12, que fecha a injeção de CRLF na regra `email`, e patches de `league/commonmark`, `symfony/http-foundation` e `symfony/yaml`; `composer audit` zerado; models com `HasVersion4Uuids` para manter o UUID v4; mensagem de material inválido mantida como era, ver "Decisões pendentes"; o front saiu como M-34) | #41 |
| M-24 | Migrar checkout, webhook e painel para o `ProductionWorkflowService` (dívida da regra 5) | C | checkout, pedidos, painel | M-16, M-17 | concluído (novo `transitionFrom()` com origem esperada: tela antiga ou estorno atrasado não cancela pedido pago; toda transição dispara `OrderStatusUpdatedEvent`; notas fora do log do servidor; runbook de revisão usa o serviço) | #38 |
| M-25 | CSP sem `unsafe-eval`/`unsafe-inline` onde o Filament permitir (B1) | B | rotas | M-15 | concluído (painel Filament mantém `unsafe-inline`/`unsafe-eval`: scripts inline sem nonce e Alpine com `new Function`) | #28 |
| M-26 | Área de cliente: `AuthModal` mock e usuário fixo "Fernanda" (B3). Decidido (2026-10-15): login de verdade, em 2 PRs. **(a) backend:** tabela `customers` separada de `users`, guard `customer` com sessão em cookie + CSRF (grupo `web`, só em `/api/conta/*`), cadastro, login, logout, confirmação de e-mail, "Esqueci minha senha", "Meus pedidos" pelo e-mail confirmado (inclusive pedidos antigos), cadastro com `@mimoolab.com.br` recusado. **(b) front:** `AuthModal` real, botão da conta também no celular, checkout preenchido só com login; depois do merge do (a). Plano: `/mnt/project-files/planos/m-26-login-cliente.md` | C/D | (a) rotas, config, pedidos; (b) front | (b): (a) e M-38 | (a) em revisão; (b) a fazer, deploy bloqueado pela Política de Privacidade (ver "Decisões pendentes") | — |
| M-27 | Botão "Acompanhar" dos e-mails de pedido (`confirmed`, `printing`, `shipped`) monta `$trackingUrl` com `APP_URL` e abre o endereço da API, não o da loja: usar o domínio do front (config nova) e fazer a loja abrir o rastreio a partir do link (sem router hoje). Achado no M-04 (#18) | B | pedidos, config, front | M-04 | concluído (produção precisa de `FRONTEND_URL`, ver "Antes do deploy") | #21 |
| M-28 | Texto do formulário de rastreio ainda pede "primeiros dígitos do CPF" (`OrderSearchForm.jsx:90`, `trackingSchema.js`): trocar por "CPF completo" e conferir os 11 dígitos antes de enviar, sem mudança visual. Achado no M-09 | B | front | M-09 | concluído | #25 |
| M-29 | Preço unitário negativo quando o modificador do tamanho é mais negativo que o preço base: o painel aceita (`SizeResource.php:42`, campo sem mínimo). Ex.: base 5000, tamanho −6000, frete 1490: o checkout mostra PIX R$ 5,90 e cartão R$ 4,90, mas o back trava as peças em 0 com `max(0, …)` e cobra R$ 14,90 (só o frete); `calculateProductPricing` trava em 0 e `calculateCartTotals` não. Caracterizado como PERGUNTA em `CheckoutValidationTest::test_modificador_negativo_maior_que_o_preco_gera_unitario_negativo`. Direção (a decidir no plano): proibir unitário negativo, com validação no painel e/ou 422 no checkout. Só abre com o OK do Zocca. Achado na conferência do M-18 | C | painel, checkout | M-17 | concluído (o checkout recusa com 422 peça abaixo de R$ 0,01, inclusive zero, em `items.N.size_id`/`items.N.product_id`; o painel não salva tamanho nem peça que gere essa combinação; o catálogo omite o tamanho; consulta só leitura em `docs/consultas/m-29-pecas-sem-preco.sql`) | #37 |
| M-32 | Falha depois da mudança de status (evento, listener, e-mail, job) não muda a resposta: com fila `sync` virava 500 com o pedido já pago. E `mimoo:check-production` confere a fila. Achado no M-24 | C | checkout, pedidos, painel, config | M-24 | concluído (`AfterCommitDispatcher`: falha vai para `Log::error` com `order_number`, evento, listener e classe da exceção, sem a mensagem; `check-production` reprova fila `sync`, avisa job parado há mais de 5 min e mostra `failed_jobs`) | #39 |
| M-33 | **P2.** Checagem agendada de fila parada: job disponível há mais de 15 min grava `Log::critical` (sem dados pessoais), para o aviso não depender de alguém rodar o `mimoo:check-production` à mão. Precisa do cron do scheduler (`php artisan schedule:run` a cada minuto) na hospedagem, que o Zocca confirma junto com a hospedagem. Pedido no M-32 | B | config, pedidos | M-32, hospedagem confirmada | bloqueado | — |
| M-34 | Dependências do front com falha conhecida (parte front do M-23): `npm audit fix` sem `--force` (dompurify 3.4.17, source-map-js 1.2.2, tailwindcss 3.4.19) e Vite 5 → 6.4.4 (esbuild 0.25), sem mudar o visual | B | front | M-22 | concluído (PR #40; CSS gerado igual, só espaços em `@media`; screenshots idênticas pixel a pixel; sobram 7 avisos da cadeia do Tailwind 3, só de build) | #40 |
| M-35 | Laravel 12 → 13. Exige PHP 8.3 no servidor (hoje o CI testa 8.2 e 8.3). **Prazo: antes de 24/02/2027**, quando o Laravel 12 deixa de receber correção de segurança. Pedido no M-23 | D | stack | M-23 | a fazer | — |
| M-36 | `composer audit` e `npm audit --omit=dev` no CI (`.github/workflows/ci.yml`), reprovando só avisos altos ou críticos de pacotes que chegam a produção. Pedido no M-23 | B | stack | M-23, M-34 | em merge (passos novos dentro dos jobs `backend` e `frontend`, sem check novo nem nome mudado: `composer audit --no-dev --locked --ignore-severity=low --ignore-severity=medium` e `npm audit --omit=dev --audit-level=high`; aviso alto publicado depois deixa todo PR vermelho até a dependência ser atualizada) | #42 |
| M-37 | **Opcional.** Tailwind 3 → 4, para tirar os avisos da cadeia do Tailwind 3 (risco aceito, ver "Riscos aceitos"). Preservação visual obrigatória: screenshots antes/depois e `ui-reviewer` com "visual idêntico". Pedido no M-23 | D | front, stack | M-34 | a fazer | — |
| M-38 | Usuária de demonstração "Fernanda Oliveira" logada para todo visitante: o checkout abria com nome e e-mail dela preenchidos (`ShopContext.jsx`, `CheckoutModal.jsx`), e quem não apagou fez pedido com `fernanda@mimoolab.com.br`. Começar sem usuário, checkout vazio, esconder o botão da conta e o `AuthModal` falso até o M-26; consulta só leitura dos pedidos afetados. Pedido do Zocca na thread do M-26 | B | front | — | em revisão (rodar `docs/consultas/m-38-pedidos-email-fernanda.sql` e avisar os clientes listados) | — |
| M-39 | Frete calculado com float (`ShippingCalculationService.php:115` preço do Melhor Envio e `:188` tabela de reserva, `round(float × 100)`, contra a regra 2). Verificado: o float no `ShippingCalculationService` (linhas 115 e 188) não altera nenhum valor: 9 preços da tabela de reserva e 10 milhões de valores do Melhor Envio (R$ 0,01 a R$ 100.000,00) testados sem erro em 2026-10-17. Decisão do Zocca: não mudar o código; exceção legada anotada no `CLAUDE.md`. A limpeza virou o M-40 | A | — | — | em merge (só documentação) | — |
| M-40 | **P3.** Unificar `MoneyInput::toCents` e a conversão do `GatewayPayment` (`amountToCents`) num conversor único só com inteiros e usá-lo no frete: tabela de reserva escrita em centavos e preço do Melhor Envio convertido sem float, mantendo o campo `price` da resposta de `/api/shipping/calculate`. Teste provando os mesmos 9 valores da tabela e os mesmos resultados do Melhor Envio. Pedido no M-39 | B | checkout, painel | M-39 | a fazer | — |

## Decisões pendentes

Registradas para o Zocca decidir. Até lá, vale o comportamento atual ou o mais conservador.

| Decisão | Comportamento até decidir | Afeta |
|---|---|---|
| Recusar tamanho inativo no checkout? A tabela `sizes` não tem `is_active` (exigiria migration, classe D). | Todo tamanho vinculado ao produto é aceito. | M-03, M-11 |
| `/api/colors` e `/api/sizes`: criar a coluna `is_active` (migration) ou listar tudo? | M-11 lista todas as cores/tamanhos (sem migration). | M-11 |
| **Bloqueia o deploy do M-26 (b):** página de Política de Privacidade publicada, dizendo quais dados a conta guarda e como pedir a exclusão da conta. Texto e endereço são do Zocca. | O M-26 (a) pode ir para produção (as rotas não aparecem na loja). O (b) não vai para produção sem a página. | M-26 |
| Excluir a conta pelo próprio site (LGPD)? | Exclusão a pedido do cliente, pelo canal que a Política de Privacidade indicar; feita à mão no banco (o painel não lista contas). | M-26 ou tarefa nova |
| Riscos da conta de cliente apontados na revisão de segurança do M-26 (a): (1) o cadastro diz "Este e-mail já tem conta", o que revela quem é cliente (limite de 3 por minuto por IP); (2) o token de "nova senha" fica no `payload` da fila (`jobs`, e `failed_jobs` se o envio falhar) até o e-mail sair, como no padrão do Laravel. Aceitar ou mudar? | Mantidos: (1) era o comportamento do plano aprovado; (2) quem lê o banco já vê os dados dos clientes, e o token vale 60 min. | M-26 |
| Painel para contas de cliente (ver, bloquear, excluir), endereços salvos, "comprar de novo", login com Google? | Fora do M-26. | tarefa nova |
| Cancelar pedido já pago no painel: estorno automático pelo Mercado Pago ou manual? | M-10 cancela só pedido não pago; pago fica para estorno manual. | M-10 |
| Validade do PIX (`date_of_expiration`): qual prazo? | Prazo padrão do Mercado Pago. | M-17 ou tarefa nova |
| Frete grátis como política futura (valor mínimo, regiões)? | Sem frete grátis (M-02). | — |
| Pedido `pending_payment` sem pagamento (falha do gateway sem cobrança, recusa sem webhook) não expira: não há job que cancele pendentes antigos. Prazo e regra? | Fica pendente até o webhook ou o admin resolver. | tarefa nova (achado no M-08) |
| Pedido em "Pagamento em revisão" que o Zocca decide estornar: o "Cancelar Pedido" fica escondido nele, então ele segue pendente no painel. Mostrar o cancelar ali, com aviso "estorne no Mercado Pago antes"? | Cancelar escondido em pedido em revisão. | M-10 |
| E-mail ao cliente quando o pedido é cancelado ou entregue? Hoje não existe. | Sem e-mail. | tarefa nova |
| Brinde / peça grátis (preço R$ 0,00)? Se um dia existir, será uma regra própria no backend. | M-29 recusa no checkout toda peça abaixo de R$ 0,01, inclusive zero. | tarefa nova |
| Rastreador de erros (Sentry, Flare etc.): decidido (M-32) que, quando entrar, uma falha depois da mudança de status reporta uma exceção nova só com a classe da original e o `order_number`, nunca a mensagem (pode trazer o e-mail do cliente). | Sem rastreador: só `Log::error` sem a mensagem da exceção. | tarefa nova |
| Mensagem de material inválido no checkout: no Laravel 12 a mensagem própria "O material selecionado não é um polímero homologado pela MIMOO LAB." passaria a aparecer (no 11 nunca aparecia). Usar essa ou manter a padrão? | M-23 tirou a mensagem própria: segue "O valor escolhido em material é inválido.", como hoje. A loja só envia materiais válidos, então o cliente não vê nenhuma das duas. | M-23 |
| E-mail de confirmação promete "~4 horas de extrusão" (o campo `estimated_total_print_time_hours` não existe; sempre cai no 4). Manter, tirar ou calcular? | Texto atual mantido (M-04 não mudou o texto). | tarefa nova |

## Riscos aceitos

| Risco | Por que aceito | Revisar em |
|---|---|---|
| Avisos do `npm audit` na cadeia do Tailwind 3 (`braces`, `micromatch`, `chokidar`, `fast-glob`, `postcss-nested`, `postcss-selector-parser`). `braces` não tem versão corrigida. | Rodam só no build, lendo o nosso próprio código; nada disso vai para o navegador. Só o Tailwind 4 tira a cadeia, com risco alto de mudar o visual (M-23, decisão do Zocca em 2026-10-15). | M-37 |

## Antes do deploy

- Rodar `/mnt/project-files/consultas/produtos-sem-cor-ou-tamanho.sql` (só leitura) na produção e corrigir no
  painel os produtos sem cor/tamanho vinculados; depois do M-03 eles passam a dar 422 no checkout.
- Rodar `docs/consultas/m-29-pecas-sem-preco.sql` (só leitura; pode ser antes do deploy) e corrigir no painel as
  peças/tamanhos que ficam abaixo de R$ 0,01; depois do M-29 essas combinações dão 422 no checkout.
- `.env` de produção com `ADMIN_EMAIL`/`ADMIN_PASSWORD` (M-01), `MERCADOPAGO_WEBHOOK_SECRET`, `APP_ENV=production`,
  `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; trocar a senha do admin também no servidor publicado.
- `.env` de produção com `CORS_ALLOWED_ORIGINS` = domínio do frontend publicado (M-05; sem ela a API só aceita as
  origens de desenvolvimento) e `APP_URL` em `https://`. Depois de `php artisan config:cache`, rodar
  `php artisan mimoo:check-production`: precisa terminar com "Configuração de produção ok." (código 0).
- Build do front com `VITE_API_URL` = endereço da API publicada, sem `/api` no final (M-07; ex.:
  `VITE_API_URL=https://api.dominio.com.br npm run build`). Sem ela, o site publicado chama `http://localhost:8000`.
- E-mails ao cliente (M-04): `.env` de produção com `MAIL_MAILER` e os dados do SMTP, e um `php artisan queue:work`
  rodando sempre (a fila é `database`; sem worker os e-mails ficam parados na tabela `jobs`). `QUEUE_CONNECTION=sync`
  reprova o `mimoo:check-production` (M-32). Até o Zocca confirmar a hospedagem, valem as duas variantes:
  - **VPS / servidor próprio:** supervisor mantendo `php artisan queue:work --tries=3 --sleep=3` (ex.: arquivo
    `/etc/supervisor/conf.d/mimoo-worker.conf` com `command=php /caminho/backend/artisan queue:work --tries=3 --sleep=3`,
    `autostart=true`, `autorestart=true`, `user=<usuário do site>`), e `php artisan queue:restart` a cada deploy;
  - **hospedagem sem processo permanente:** cron a cada minuto,
    `* * * * * cd /caminho/backend && php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1`.
  Depois do deploy, o `mimoo:check-production` mostra se há job parado há mais de 5 min e quantos jobs falharam. Se usar
  `php artisan event:cache`, rodar de novo a cada deploy. PIX (webhook) e cartão aprovado (M-08) mandam o e-mail de
  confirmação; "Iniciar Impressão" e "Expedir Pedido" do painel mandam os e-mails de impressão e de envio (M-10). O botão "Acompanhar" dos e-mails usa `FRONTEND_URL` (M-27, abaixo).
- `.env` de produção com `FRONTEND_URL` = endereço da loja publicada, em `https://` e sem barra no final (M-27; ex.:
  `FRONTEND_URL=https://www.dominio.com.br`). Sem ela, o botão "Acompanhar" dos e-mails aponta para `http://localhost:5173`.
- Laravel 12 (M-23): o servidor precisa de PHP 8.2 ou mais novo. No deploy, dentro de `backend/`, nesta ordem:
  `composer install --no-dev --optimize-autoloader`, `php artisan optimize:clear`, `php artisan migrate --force` (o M-23
  não traz migration; o passo fica para as próximas), `php artisan config:cache`, `php artisan queue:restart` (o worker
  antigo ainda tem o Laravel 11 carregado) e `php artisan mimoo:check-production`, que precisa terminar com
  "Configuração de produção ok.".
- Conta de cliente (M-26 a): `php artisan migrate --force` cria `customers` e `customer_password_reset_tokens`. Loja e API
  no mesmo domínio (ex.: loja `https://mimoolab.com.br`, API `https://api.mimoolab.com.br`), porque o login usa cookie.
  No `.env` da API: `SESSION_DOMAIN=.mimoolab.com.br` (com o ponto, para a loja ler o cookie `XSRF-TOKEN`; vazio se a API
  for servida em `https://mimoolab.com.br/api`), `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` (o padrão),
  `CORS_ALLOWED_ORIGINS` com o endereço exato da loja (nunca `*`: o CORS agora aceita cookie) e `FRONTEND_URL` (os links
  dos e-mails de conta abrem a loja). O cadastro só vale com SMTP e worker da fila funcionando (os e-mails de
  confirmação e de nova senha vão pela fila `database`, como os de pedido). Com loja e API em
  endereços diferentes e `SESSION_DOMAIN` errado, o `mimoo:check-production` avisa (todo login daria 419). Verificar
  depois do deploy do (b) (o (a) sozinho não tem tela): criar uma conta de teste com um e-mail seu, conferir que
  "Confirme seu e-mail na MIMOO LAB" chega em até 1 min, abrir o link (abre a loja, que confirma com você logado),
  pedir "Esqueci minha senha" e conferir o segundo e-mail; se algum não chegar, `php artisan mimoo:check-production`
  mostra job parado ou falhado.
- Build do front com Vite 6 (M-34): Node `^18`, `^20` ou `>=22` (o `engines` do Vite 6.4.4); usar Node 22, o mesmo do CI
  (o 18 já não recebe correção). O build roda onde o `npm run build` for executado (não há deploy automático ligado ao
  repositório): conferir `node -v` nessa máquina antes do build e rodar `npm ci` depois do pull.
