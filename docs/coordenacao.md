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
`em andamento` · `bloqueado`.

| # | Tarefa | Classe | Zonas | Depende de | Status | PR |
|---|---|---|---|---|---|---|
| M-00 | Criar este arquivo de coordenação | A | — | — | concluído | #13 |
| M-01 | Trocar a senha do admin e tirar e-mail/senha padrão do `AdminUserSeeder` (C4) | B | config | — | concluído (senha local já trocada; servidor publicado precisa trocar à parte) | #11 |
| M-02 | Tirar a promessa de frete grátis da loja | B | front | — | concluído | #10 |
| M-03 | Validar cor, tamanho e material do próprio produto no checkout (bugs #5, #6, #19) | C | checkout, front | — | concluído | #12 |
| M-04 | E-mails ao cliente: listeners com relações certas (#13), confirmação registrada uma vez só (#23), e-mail fora dos logs dos listeners/job (doc-vs-código) | C | pedidos | — | a fazer | — |
| M-05 | Config de produção: tirar `'*'` do CORS (A4), `SESSION_SECURE_COOKIE` no `.env.example` (M3), checagem que falha com `APP_DEBUG=true` em produção (C3) | B | config (+ `app/Console/Commands/`, sem zona) | — | concluído (o `.env.example` é bloqueado para o Claude: o Zocca acrescenta `SESSION_SECURE_COOKIE` e `CORS_ALLOWED_ORIGINS` à mão; em produção o cookie já é Secure por padrão) | #14 |
| M-06 | SDK do Mercado Pago em `SERVER` fora do ambiente local (M2) | C | checkout | — | a fazer | — |
| M-07 | Front: trocar `localhost:8000` fixo por `import.meta.env.VITE_API_URL` em `ShopContext`, `CheckoutModal`, `useMercadoPagoCard`, `useShipping`, `trackingService` (B2) | B | front | M-02 | a fazer | — |
| M-08 | Erro do gateway mantém pedido pendente com 502 genérico (#8); cartão recusado responde 422 amigável (#9); cartão aprovado dispara `OrderPaidEvent` (#20); mensagens no `CheckoutModal` | C | checkout, front | M-03, M-04 | a fazer | — |
| M-09 | Privacidade do rastreio: CPF completo em vez de prefixo (#11), timeline sem notas internas (#12), só POST (#17) | C | rastreio, rotas | — | a fazer | — |
| M-10 | Painel: ações de cancelar e de marcar como entregue; ações de produção disparam os eventos com listener (#21) | C | painel, pedidos | M-04 | a fazer | — |
| M-11 | `/api/colors` e `/api/sizes` filtram por `is_active`, coluna que não existe (500 no Postgres) | B | rotas | M-09 | a fazer | — |
| M-12 | Simplificar a tela de materiais e cores no painel | B | painel | M-10 | a fazer | — |
| M-13 | Compra rápida do card do catálogo escolhe cor compatível com o material (hoje pode cair no 422 do M-03) | B | front | M-03 | a fazer | — |
| M-14 | Higiene: limpeza de hooks do `ProductDetailModal` (13 erros `rules-of-hooks` do oxlint hoje; sem mudança visual) | B | front | M-03 | a fazer | — |
| M-15 | Rate limit separado para checkout, rastreio e frete (#18) | B | rotas | M-11 | a fazer | — |
| M-16 | `transitionTo()` valida contra o status do banco (#15); `dispatchOrder()` só grava o código após validar (#22) | C | pedidos | M-04, M-10 | a fazer | — |
| M-17 | Webhook: cancelamento confere o payment id em `order_payments` e usa update condicional (#24); `OrderPaidEvent` só após o commit | C | checkout | M-08 | a fazer | — |
| M-18 | Desconto PIX igual no front e no back (diferença de 1 centavo; skill `pricing-sync`) | C | checkout, front | M-08 | a fazer | — |
| M-19 | Sanitização de CPF/telefone/CEP do `CheckoutRequest` chegar ao DTO | C | checkout | M-17 | a fazer | — |
| M-20 | Mensagens de validação em pt-BR (`lang/pt_BR`) | B | config | M-05 | a fazer | — |
| M-21 | Catálogo: trocar o polling de 3 s do `ShopContext` por algo mais leve, sem mudar a tela | B | front | M-07 | a fazer | — |
| M-22 | CI no GitHub Actions com Postgres (suíte, `pint --test`, lint, build); `oxlint` como devDependency (hoje `npm run lint` falha com "oxlint: not found") e lint sem varrer `backend/public/` | D | stack | M-01 a M-03 mergeados | a fazer | — |
| M-23 | `composer audit` e `npm audit` e correção do que aparecer | D | stack | M-22 | a fazer | — |
| M-24 | Migrar checkout, webhook e painel para o `ProductionWorkflowService` (dívida da regra 5) | C | checkout, pedidos, painel | M-16, M-17 | a fazer | — |
| M-25 | CSP sem `unsafe-eval`/`unsafe-inline` onde o Filament permitir (B1) | B | rotas | M-15 | a fazer | — |
| M-26 | Área de cliente: `AuthModal` mock e usuário fixo "Fernanda" (B3) | C | front | decisão pendente | bloqueado | — |

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

## Antes do deploy

- Rodar `/mnt/project-files/consultas/produtos-sem-cor-ou-tamanho.sql` (só leitura) na produção e corrigir no
  painel os produtos sem cor/tamanho vinculados; depois do M-03 eles passam a dar 422 no checkout.
- `.env` de produção com `ADMIN_EMAIL`/`ADMIN_PASSWORD` (M-01), `MERCADOPAGO_WEBHOOK_SECRET`, `APP_ENV=production`,
  `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; trocar a senha do admin também no servidor publicado.
- `.env` de produção com `CORS_ALLOWED_ORIGINS` = domínio do frontend publicado (M-05; sem ela a API só aceita as
  origens de desenvolvimento) e `APP_URL` em `https://`. Depois de `php artisan config:cache`, rodar
  `php artisan mimoo:check-production`: precisa terminar com "Configuração de produção ok." (código 0).
