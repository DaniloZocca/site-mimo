# MIMOO LAB 3D Studio — site-mimo

Loja brasileira de colecionáveis impressos em 3D sob encomenda (FDM). Só imprimimos após pagamento confirmado.
Monorepo: frontend React 19 + Vite (raiz, `src/`) + API Laravel 11 + Filament 3 (`backend/`). UI, mensagens e commits em pt-BR.
Antes de tarefas de segurança/pagamento, leia `RELATORIO_SEGURANCA_MIMOO.md` (achados abertos C1–B3).

## Comandos
- Front: `npm run dev` | `npm run lint` (oxlint) | `npm run build`. Ainda não há testes de front (Vitest não instalado).
- Back (dentro de `backend/`): `php artisan serve` (porta 8000) | `php artisan test` (PHPUnit) | `vendor/bin/pint --dirty`
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
3. Desconto PIX de 10% incide só no subtotal das peças, nunca no frete. Backend é a fonte da verdade; `src/utils/pricing.js` espelha só para exibição — mudou um, atualize o outro (skill `pricing-sync`).
4. Pedido é commitado ANTES de chamar o Mercado Pago. Nenhuma chamada HTTP externa dentro de `DB::transaction`.
5. Status de pedido só muda via `ProductionWorkflowService::transitionTo()` (mapa `ALLOWED_TRANSITIONS`), gravando `ProductionLog`.
6. Pagamento só é aceito após `MercadoPagoService::getPayment()` confirmar `approved` + valor igual a `total_cents` + `external_reference`. Webhook: assinatura válida (fail-closed), evento idempotente, resposta 200 rápida.
7. Rastreio anti-IDOR: `order_number` + CPF, ou `tracking_token`. Nunca exponha dados pessoais sem máscara.
8. CPF, e-mail, telefone, tokens e segredos nunca vão para logs, exceções ou respostas.
9. `OrderItem` é snapshot imutável: não recalcule pedidos antigos a partir de `Product`.
10. Nada de atalhos de teste em código de produção (ex.: IDs `SIM_`/`TEST_` que simulam pagamento aprovado). Simulação vive em testes com `Http::fake()`/mocks.

## Como trabalhar neste repo
- Leia os arquivos envolvidos e o padrão existente mais próximo antes de editar.
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
- Banco: SQLite. `lockForUpdate()` não trava nada no SQLite — use update condicional (`WHERE status = ?`) para evitar corrida.
- `MercadoPagoWebhookController` hoje altera `status` direto, sem o `ProductionWorkflowService` (dívida conhecida; ao mexer ali, migre para o serviço).

## Compactação
Ao compactar, preserve: arquivos modificados, comandos de teste usados, decisões sobre dinheiro/status e achados de segurança em aberto.
