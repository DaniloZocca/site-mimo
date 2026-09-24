# Auditoria de Segurança e Revisão Arquitetural — MIMOO LAB 3D Studio

**Data:** 23/09/2026
**Escopo:** Backend Laravel 11 + Filament 3, Frontend React 19 + Vite, integração Mercado Pago (PIX + Cartão), rastreio público de pedidos.
**Metodologia:** Revisão de código (SAST manual) + testes dinâmicos em cópia local isolada da API (checkout, webhook, rastreio, rate limit, headers, painel admin) + verificação de dependências.

---

## Resumo executivo

O projeto está bem estruturado para uma loja on-demand: Zero-Trust de preço das peças **funciona**, os snapshots imutáveis em `order_items` estão corretos, a atomicidade (commit no banco antes de chamar o gateway) está correta, o rate limiting responde (429), os cabeçalhos de segurança estão presentes e o painel admin exige login. Os 9 testes automatizados passam.

Porém há **1 falha crítica que permite marcar pedidos como pagos sem pagamento real** e alguns furos de lógica de negócio que quebram parcialmente a promessa "Zero-Trust". A tabela abaixo prioriza a correção.

| # | Severidade | Achado | Estado |
|---|-----------|--------|--------|
| C1 | 🔴 Crítico | Webhook aceita notificação forjada quando `MERCADOPAGO_WEBHOOK_SECRET` está vazio (fail-**open**) | Confirmado (dinâmico) |
| C2 | 🔴 Crítico | Backdoor `getPayment()` retorna `approved` para IDs `SIM_`/`TEST_` em código de produção | Confirmado (código) |
| C3 | 🔴 Crítico | `.env` com `APP_DEBUG=true` e `APP_ENV=local`; risco de vazamento total em produção | Confirmado |
| C4 | 🟠 Alto | Senha de admin **hardcoded** no `AdminUserSeeder` (vai para o Git) | Confirmado |
| A1 | 🟠 Alto | Frete (`shipping_cost_cents`) definido pelo cliente sem recálculo no servidor | Confirmado (dinâmico) |
| A2 | 🟠 Alto | Cor não vinculada ao produto é aceita no checkout | Confirmado (dinâmico) |
| A3 | 🟠 Alto | Tamanho não vinculado ao produto é aceito e seu modificador de preço é aplicado | Confirmado (dinâmico) |
| A4 | 🟠 Alto | CORS com `allowed_origins` contendo `'*'` | Confirmado |
| M1 | 🟡 Médio | Rastreio aceita prefixo de CPF de 1 dígito (LGPD / enumeração de PII) | Confirmado (dinâmico) |
| M2 | 🟡 Médio | SDK Mercado Pago fixado em `LOCAL` runtime environment | Confirmado |
| M3 | 🟡 Médio | Cookies de sessão sem `Secure`; `SESSION_SECURE_COOKIE` não definido | Confirmado |
| B1 | 🔵 Baixo | CSP com `unsafe-inline` + `unsafe-eval` em `script-src` | Confirmado |
| B2 | 🔵 Baixo | Frontend com `API_BASE` e polling apontando para `localhost:8000` hardcoded | Confirmado |
| B3 | 🔵 Baixo | `AuthModal` é mock (login falso, usuário fixo "Fernanda") | Confirmado |
| B4 | 🔵 Baixo | Inconsistência SQLite × PostgreSQL (dossiê diz SQLite; código usa `jsonb`/pgsql) | Confirmado |

---

## 🔴 CRÍTICOS

### C1 — Webhook do Mercado Pago é "fail-open" com secret vazio

**Onde:** `app/Http/Controllers/Api/MercadoPagoWebhookController.php:51` e `app/Services/MercadoPagoService.php:219`

Toda a validação HMAC (presença de headers, anti-replay e comparação de assinatura) está dentro de:

```php
if (! empty($webhookSecret)) {
    // ... valida x-signature, timestamp, HMAC ...
}
```

Se `MERCADOPAGO_WEBHOOK_SECRET` estiver vazio, **o bloco inteiro é pulado** e a notificação é processada como legítima. E o seu `.env` atual tem esse campo **vazio** (o `.env.example` também). O `MercadoPagoService::validateWebhookSignature()` faz o mesmo: `if (empty($this->webhookSecret)) { ...; return true; }`.

**Prova (teste dinâmico na cópia local, secret vazio):**
```
POST /api/webhooks/mercadopago
{"type":"payment","data":{"id":"SIM_MIMOO-XXXX_x"}}
→ HTTP 200; status do pedido passou de pending_payment para in_queue
```
Com o secret configurado, a mesma requisição retorna **HTTP 401** ("Assinatura ou ID ausente"). Ou seja, a proteção existe e funciona — está apenas desligada.

**Impacto:** qualquer pessoa que conheça (ou adivinhe) a URL do webhook consegue confirmar pedidos sem pagamento. A produção 3D inicia, e-mails de confirmação são disparados e a peça pode ser fabricada/enviada de graça.

**Correção:**
1. Configure `MERCADOPAGO_WEBHOOK_SECRET` no `.env` com o secret do painel do Mercado Pago.
2. Torne a ausência do secret **fail-closed** em produção. Não confie só na variável estar preenchida:

```php
$webhookSecret = (string) config('services.mercadopago.webhook_secret', '');

if (empty($webhookSecret)) {
    if (app()->isProduction()) {
        Log::critical('Webhook rejeitado: secret não configurado em produção.');
        return response()->json(['message' => 'Webhook não configurado.'], 503);
    }
    // fora de produção: siga só se explicitamente permitido
}
```

---

### C2 — Backdoor de pagamento aprovado para IDs `SIM_`/`TEST_`

**Onde:** `app/Services/MercadoPagoService.php:178-192`

```php
public function getPayment(string|int $paymentId): ?array
{
    if (is_string($paymentId) && (str_starts_with($paymentId, 'SIM_') || str_starts_with($paymentId, 'TEST_'))) {
        // ... retorna status 'approved' com valor = total do pedido
    }
    ...
}
```

Esse atalho de teste vive dentro do serviço de produção. `getPayment()` é chamado pelo webhook usando o `data.id` **enviado por quem chama**. Combinado com C1 (sem secret), é exatamente o vetor que usei para aprovar um pedido no teste dinâmico: basta mandar `data.id = "SIM_<numero-do-pedido>_x"`. Mesmo com o secret ativo, esse código não deve existir num binário de produção.

**Correção:** remover o bloco `SIM_`/`TEST_` do serviço. Se precisar simular fluxo em testes, faça-o via mock/fake do serviço na suíte de testes (`$this->mock(MercadoPagoService::class)`), nunca no código real. Guarde qualquer simulação atrás de `if (! app()->isProduction())`.

---

### C3 — Configuração de produção insegura no `.env`

**Onde:** `backend/.env`

```
APP_ENV=local
APP_DEBUG=true
```

Se o projeto subir para produção com esses valores (comum quando se copia o `.env` de dev), qualquer exceção mostra o **Ignition/stack trace** com trechos do código, variáveis de ambiente, credenciais do banco e do Mercado Pago. Também desativa otimizações e libera rotas de debug.

**Correção (produção):**
```
APP_ENV=production
APP_DEBUG=false
```
E rode `php artisan config:cache` no deploy. Considere um teste de fumaça no deploy que falhe se `APP_DEBUG=true` em `production`.

---

### C4 — Senha de administrador hardcoded no seeder

**Onde:** `database/seeders/AdminUserSeeder.php:19-20`

```php
$adminEmail = env('ADMIN_EMAIL', 'zoccaverderame@outlook.com');
$adminPassword = env('ADMIN_PASSWORD', 'N287$yQv-G-x');
```

Apesar do comentário dizer "não armazena senhas em texto puro", o **fallback** grava e-mail e senha reais no código-fonte — que vai para o Git. Quem tiver acesso ao repositório tem a senha do painel admin.

**Correção:** remover os fallbacks. Falhe se as variáveis não existirem, e **troque essa senha agora** (ela deve ser considerada comprometida).

```php
$adminEmail = env('ADMIN_EMAIL');
$adminPassword = env('ADMIN_PASSWORD');

if (empty($adminEmail) || empty($adminPassword)) {
    throw new \RuntimeException('Defina ADMIN_EMAIL e ADMIN_PASSWORD no .env para criar o admin.');
}
```

---

## 🟠 ALTOS

### A1 — Frete é definido pelo cliente, sem recálculo no servidor

**Onde:** `OrderCreationService.php:120` (`+ (int) $dto->shippingCostCents`) e `CheckoutRequest.php:98`

O "Zero-Trust Pricing" recalcula o preço das **peças**, mas o **frete** vem do payload do cliente (`shipping_cost_cents`) e é gravado como veio. No teste dinâmico enviei `shipping_cost_cents: 0` e o pedido foi criado com frete zero (HTTP 201). Um cliente pode zerar ou reduzir o frete arbitrariamente.

**Correção:** no checkout, recalcule o frete no servidor com o `ShippingCalculationService` (mesmo CEP + itens) e use esse valor, ou valide que o valor recebido corresponde a uma cotação válida (por exemplo, cotação assinada/cacheada por token). Nunca confie no número do cliente.

---

### A2 — Cor não pertencente ao produto é aceita

**Onde:** `OrderCreationService.php:54-60`

A validação só verifica se a cor existe e está `in_stock`. Nunca confere se a cor está **vinculada ao produto** (`color_product`). No teste, passei uma cor de outro produto e o checkout aceitou (HTTP 201).

**Correção:** validar o vínculo. Ex.:
```php
if (! $product->colors()->whereKey($color->id)->exists()) {
    throw ValidationException::withMessages([
        'items' => ["A cor '{$color->name}' não está disponível para '{$product->title}'."],
    ]);
}
```
(Idealmente pré-carregue `Product::with('colors','sizes')` para não gerar N+1.)

---

### A3 — Tamanho não vinculado ao produto é aceito (bypass parcial de preço)

**Onde:** `OrderCreationService.php:62-68, 94`

Mesma falha da A2, mas com impacto financeiro: o `Size` é buscado globalmente e seu `price_modifier_in_cents` é somado ao preço, sem verificar se o tamanho é ofertado para aquele produto. No teste, um produto que só oferece o tamanho "Maxi (+R$65)" aceitou o tamanho "Pequeno" (modificador R$ 0) — cliente monta uma variação que a loja não vende e paga menos.

**Correção:** validar `$product->sizes()->whereKey($size->id)->exists()` antes de aplicar o modificador, com a mesma mensagem 422.

---

### A4 — CORS liberado com `'*'`

**Onde:** `config/cors.php`

```php
'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000', '*'],
```

O `'*'` anula a lista específica: qualquer site pode chamar a API. Com `supports_credentials=false` o risco é menor (não vaza cookies), mas ainda expõe endpoints a qualquer origem.

**Correção:** remover `'*'` e deixar só os domínios reais de produção (o do frontend publicado) + os de dev.

---

## 🟡 MÉDIOS

### M1 — Rastreio aceita prefixo de CPF de 1 dígito (LGPD)

**Onde:** `OrderTrackingController.php:39` — `str_starts_with($cpf, $cpfPrefix)`

Com `order_number` + `cpf_prefix`, a validação passa com **qualquer** número de dígitos, inclusive 1. No teste, `cpf_prefix` com 1 dígito retornou HTTP 200 e vazou nome, cidade/UF, itens e timeline. Os números de pedido são ULID (não sequenciais), o que dificulta enumeração, mas o fator de autenticação de 1 dígito é fraco (10% de acerto por tentativa; o throttle de 10/min ajuda pouco).

**Correção:** exigir CPF **completo** (11 dígitos, validado) ou e-mail exato. Remova o modo "prefixo", ou exija no mínimo o CPF inteiro. Mantenha a resposta genérica de 404 (já está boa).

### M2 — SDK Mercado Pago fixo em `LOCAL`

**Onde:** `MercadoPagoService.php:28` — `MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL)`

Deveria ser `SERVER` em produção. Ajuste condicionalmente por ambiente.

### M3 — Cookies de sessão sem `Secure`

**Onde:** `config/session.php:173` + `.env` (`SESSION_SECURE_COOKIE` ausente)

Em produção (HTTPS), defina `SESSION_SECURE_COOKIE=true`. O painel Filament usa sessão por cookie; sem `Secure`, o cookie pode trafegar em HTTP. `same_site=lax` e `http_only=true` já estão ok.

---

## 🔵 BAIXOS / Arquitetura

- **B1 — CSP fraca:** `script-src` usa `'unsafe-inline'` e `'unsafe-eval'`. É exigido por Filament/Livewire, mas enfraquece a defesa contra XSS. Meta futura: nonce por request. (A sanitização do frontend com DOMPurify em `ProductDetailModal` está boa; `FORBID_ATTR` cobre handlers de evento.)
- **B2 — URLs hardcoded no frontend:** `http://localhost:8000/api` aparece em `ShopContext.jsx`, `CheckoutModal.jsx`, `useMercadoPagoCard.js`, `useShipping.js`, `trackingService.js`. Além de quebrar em produção, o polling de 3s em `ShopContext` bate direto em localhost. Centralize em `import.meta.env.VITE_API_URL`.
- **B3 — Autenticação de cliente é mock:** `AuthModal.jsx` faz login falso e o `ShopContext` inicia com usuário fixo "Fernanda Oliveira" logado. Não é vulnerabilidade (pedidos são por convidado + CPF/e-mail), mas é código de demonstração que não deve ir para produção. Decida: remover a área logada ou implementar auth real (ex.: Sanctum).
- **B4 — SQLite × PostgreSQL:** o dossiê diz "SQLite (UUIDs)", mas o `.env` usa `pgsql` e as migrations usam `jsonb` e `useCurrent()` (específicos de Postgres) — `jsonb` nem existe no SQLite. Escolha um banco e alinhe dossiê + migrations. Recomendo **PostgreSQL** para produção (já é o que o código assume).
- **Polling:** `ShopContext` recarrega o catálogo a cada 3s + no foco da janela. Em produção isso é carga desnecessária no servidor. Considere WebSocket/SSE só para o "LiveFarmTicker" e cache HTTP no catálogo.

---

## ✅ O que está correto (não mexer)

- **Zero-Trust de preço das peças:** confirmado — enviei `unit_price_cents: 1` e o total gravado foi o correto (R$ 63,41). Preço vem sempre de `base_price_cents + price_modifier`.
- **Snapshots imutáveis** em `order_items` (título, cor, tamanho, material, preço).
- **Atomicidade:** o pedido é persistido no banco antes de chamar o gateway; falha de rede vira `cancelled` com log de auditoria.
- **Restrição de polímero:** `allowed_materials` é validado corretamente (material `tpu` num produto só-`pla` → 422).
- **Desconto PIX 10%** aplicado no servidor, só sobre as peças (frete não).
- **Rate limiting:** 10/min em checkout e rastreio → 429 confirmado.
- **Cabeçalhos de segurança:** CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy presentes; `X-Powered-By`/`Server` removidos.
- **Painel admin:** exige login (302) e `canAccessPanel` checa `is_admin`.
- **Validações de quantidade** (min 1, max 50), CPF (`ValidCpf`), UUIDs e `exists` nos itens funcionam.
- **Cartão:** apenas token do Brick trafega (nunca PAN/CVV) — PCI-DSS SAQ A correto.
- **Idempotência do webhook** (`lockForUpdate` + checagem de status) e **anti-fraude de valor** (`paidAmountInCents < total_cents`).

---

## Plano de ação sugerido (ordem)

1. **Hoje:** configurar `MERCADOPAGO_WEBHOOK_SECRET` (C1) e trocar a senha do admin (C4).
2. **Antes de qualquer deploy:** remover backdoor `SIM_/TEST_` (C2); `APP_DEBUG=false`/`APP_ENV=production` (C3); remover `'*'` do CORS (A4); `SESSION_SECURE_COOKIE=true` (M3).
3. **Correções de lógica:** recalcular frete no servidor (A1); validar vínculo de cor e tamanho ao produto (A2/A3); exigir CPF completo ou e-mail no rastreio (M1).
4. **Higiene:** `VITE_API_URL` no frontend (B2); decidir banco e alinhar migrations (B4); remover/implementar auth de cliente (B3); SDK MP `SERVER` (M2).
5. **Dependências:** rodar `composer audit` e `npm audit` num ambiente com acesso à internet (não consegui checar CVEs aqui por bloqueio de rede). Versões atuais são recentes (Laravel 11.56, Filament 3.3, MP SDK 3.16).

> Observação: os testes dinâmicos rodaram numa cópia isolada do projeto no meu ambiente (SQLite), sem tocar nos seus dados. Nenhuma alteração foi feita no seu código-fonte.
