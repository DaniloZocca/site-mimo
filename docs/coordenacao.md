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
  último commit, sem conflito, comentários resolvidos, saídas coladas). Enquanto não houver CI (não existe
  `.github/`), "CI verde" = atualizar o PR com a `main` atual e rodar suíte completa, `pint --test`, lint e
  build nesse estado, colando a saída. Depois do merge, avisar na thread o que entrou e o que fazer antes do deploy.
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
| M-12 | Simplificar a tela de materiais e cores no painel | B | painel | M-10 | a fazer | — |
| M-13 | Compra rápida do card do catálogo escolhe cor compatível com o material (hoje pode cair no 422 do M-03) | B | front | M-03 | concluído (sem cor compatível em nenhum material, o botão abre os detalhes do produto) | #16 |
| M-14 | Higiene: limpeza de hooks do `ProductDetailModal` (13 erros `rules-of-hooks` do oxlint hoje; sem mudança visual) | B | front | M-03 | concluído (oxlint sem erros em `src/`; o conteúdo do modal remonta a cada produto aberto, como já acontecia na prática) | #17 |
| M-15 | Rate limit separado para checkout, rastreio e frete (#18) | B | rotas | M-11 | concluído (contador próprio por grupo de rotas; limites 10/10/30 por minuto mantidos) | #27 |
| M-16 | `transitionTo()` valida contra o status do banco (#15); `dispatchOrder()` só grava o código após validar (#22) | C | pedidos | M-04, M-10 | a fazer | — |
| M-17 | Webhook: cancelamento confere o payment id em `order_payments` e usa update condicional (#24); `OrderPaidEvent` só após o commit | C | checkout | M-08 | a fazer | — |
| M-18 | Desconto PIX igual no front e no back (diferença de 1 centavo; skill `pricing-sync`) | C | checkout, front | M-08 | cancelado (duplicado do PR #10, conferido na `main` em 2026-10-07: o back faz `subtotal − round(subtotal × 0,10)` em `OrderCreationService.php:206-210` e o front usa a mesma conta só com inteiros em `src/utils/pricing.js:117` (`calculateProductPricing`) e `:186` (`calculateCartTotals`); fuzz de 900 mil comparações com 0 diferença; `docs/doc-vs-codigo.md:32` já marca resolvido e `docs/known-bugs.md` não tem a entrada) | #10 |
| M-19 | Sanitização de CPF/telefone/CEP do `CheckoutRequest` chegar ao DTO | C | checkout | M-17 | a fazer | — |
| M-20 | Mensagens de validação em pt-BR (`lang/pt_BR`) | B | config | M-05 | concluído (locale padrão `pt_BR` no config; se o `.env` do servidor tiver `APP_LOCALE=en`, trocar para `pt_BR`) | #19 |
| M-21 | Catálogo: trocar o polling de 3 s do `ShopContext` por algo mais leve, sem mudar a tela | B | front | M-07 | concluído (busca ao abrir, a cada 60 s só com a aba visível e ao voltar para a aba, no mínimo 15 s entre buscas; mudança no painel aparece na loja em até 60 s) | #20 |
| M-22 | CI no GitHub Actions com Postgres (suíte, `pint --test`, lint, build); `oxlint` como devDependency (hoje `npm run lint` falha com "oxlint: not found") e lint sem varrer `backend/public/` | D | stack | M-01 a M-03 mergeados | a fazer | — |
| M-23 | `composer audit` e `npm audit` e correção do que aparecer | D | stack | M-22 | a fazer | — |
| M-24 | Migrar checkout, webhook e painel para o `ProductionWorkflowService` (dívida da regra 5) | C | checkout, pedidos, painel | M-16, M-17 | a fazer | — |
| M-25 | CSP sem `unsafe-eval`/`unsafe-inline` onde o Filament permitir (B1) | B | rotas | M-15 | concluído (painel Filament mantém `unsafe-inline`/`unsafe-eval`: scripts inline sem nonce e Alpine com `new Function`) | #28 |
| M-26 | Área de cliente: `AuthModal` mock e usuário fixo "Fernanda" (B3) | C | front | decisão pendente | bloqueado | — |
| M-27 | Botão "Acompanhar" dos e-mails de pedido (`confirmed`, `printing`, `shipped`) monta `$trackingUrl` com `APP_URL` e abre o endereço da API, não o da loja: usar o domínio do front (config nova) e fazer a loja abrir o rastreio a partir do link (sem router hoje). Achado no M-04 (#18) | B | pedidos, config, front | M-04 | concluído (produção precisa de `FRONTEND_URL`, ver "Antes do deploy") | #21 |
| M-28 | Texto do formulário de rastreio ainda pede "primeiros dígitos do CPF" (`OrderSearchForm.jsx:90`, `trackingSchema.js`): trocar por "CPF completo" e conferir os 11 dígitos antes de enviar, sem mudança visual. Achado no M-09 | B | front | M-09 | concluído | #25 |
| M-29 | Preço unitário negativo quando o modificador do tamanho é mais negativo que o preço base: o painel aceita (`SizeResource.php:42`, campo sem mínimo). Ex.: base 5000, tamanho −6000, frete 1490: o checkout mostra PIX R$ 5,90 e cartão R$ 4,90, mas o back trava as peças em 0 com `max(0, …)` e cobra R$ 14,90 (só o frete); `calculateProductPricing` trava em 0 e `calculateCartTotals` não. Caracterizado como PERGUNTA em `CheckoutValidationTest::test_modificador_negativo_maior_que_o_preco_gera_unitario_negativo`. Direção (a decidir no plano): proibir unitário negativo, com validação no painel e/ou 422 no checkout. Só abre com o OK do Zocca. Achado na conferência do M-18 | C | painel, checkout | M-17 | a fazer | — |

## Decisões pendentes

Registradas para o Zocca decidir. Até lá, vale o comportamento atual ou o mais conservador.

| Decisão | Comportamento até decidir | Afeta |
|---|---|---|
| Recusar tamanho inativo no checkout? A tabela `sizes` não tem `is_active` (exigiria migration, classe D). | Todo tamanho vinculado ao produto é aceito. | M-03, M-11 |
| `/api/colors` e `/api/sizes`: criar a coluna `is_active` (migration) ou listar tudo? | M-11 lista todas as cores/tamanhos (sem migration). | M-11 |
| Área de cliente: remover login de mentira ou implementar login real? | Nada muda até decidir. | M-26 |
| Cancelar pedido já pago no painel: estorno automático pelo Mercado Pago ou manual? | M-10 cancela só pedido não pago; pago fica para estorno manual. | M-10 |
| Validade do PIX (`date_of_expiration`): qual prazo? | Prazo padrão do Mercado Pago. | M-17 ou tarefa nova |
| Frete grátis como política futura (valor mínimo, regiões)? | Sem frete grátis (M-02). | — |
| Pedido `pending_payment` sem pagamento (falha do gateway sem cobrança, recusa sem webhook) não expira: não há job que cancele pendentes antigos. Prazo e regra? | Fica pendente até o webhook ou o admin resolver. | tarefa nova (achado no M-08) |
| Pedido em "Pagamento em revisão" que o Zocca decide estornar: o "Cancelar Pedido" fica escondido nele, então ele segue pendente no painel. Mostrar o cancelar ali, com aviso "estorne no Mercado Pago antes"? | Cancelar escondido em pedido em revisão. | M-10 |
| E-mail ao cliente quando o pedido é cancelado ou entregue? Hoje não existe. | Sem e-mail. | tarefa nova |
| E-mail de confirmação promete "~4 horas de extrusão" (o campo `estimated_total_print_time_hours` não existe; sempre cai no 4). Manter, tirar ou calcular? | Texto atual mantido (M-04 não mudou o texto). | tarefa nova |

## Antes do deploy

- Rodar `/mnt/project-files/consultas/produtos-sem-cor-ou-tamanho.sql` (só leitura) na produção e corrigir no
  painel os produtos sem cor/tamanho vinculados; depois do M-03 eles passam a dar 422 no checkout.
- `.env` de produção com `ADMIN_EMAIL`/`ADMIN_PASSWORD` (M-01), `MERCADOPAGO_WEBHOOK_SECRET`, `APP_ENV=production`,
  `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; trocar a senha do admin também no servidor publicado.
- `.env` de produção com `CORS_ALLOWED_ORIGINS` = domínio do frontend publicado (M-05; sem ela a API só aceita as
  origens de desenvolvimento) e `APP_URL` em `https://`. Depois de `php artisan config:cache`, rodar
  `php artisan mimoo:check-production`: precisa terminar com "Configuração de produção ok." (código 0).
- Build do front com `VITE_API_URL` = endereço da API publicada, sem `/api` no final (M-07; ex.:
  `VITE_API_URL=https://api.dominio.com.br npm run build`). Sem ela, o site publicado chama `http://localhost:8000`.
- E-mails ao cliente (M-04): `.env` de produção com `MAIL_MAILER` e os dados do SMTP, e um `php artisan queue:work`
  rodando sempre (a fila é `database`; sem worker os e-mails ficam parados na tabela `jobs`). Se usar
  `php artisan event:cache`, rodar de novo a cada deploy. PIX (webhook) e cartão aprovado (M-08) mandam o e-mail de
  confirmação; "Iniciar Impressão" e "Expedir Pedido" do painel mandam os e-mails de impressão e de envio (M-10). O botão "Acompanhar" dos e-mails usa `FRONTEND_URL` (M-27, abaixo).
- `.env` de produção com `FRONTEND_URL` = endereço da loja publicada, em `https://` e sem barra no final (M-27; ex.:
  `FRONTEND_URL=https://www.dominio.com.br`). Sem ela, o botão "Acompanhar" dos e-mails aponta para `http://localhost:5173`.
