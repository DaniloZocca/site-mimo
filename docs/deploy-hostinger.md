# Deploy na Hostinger (M-50)

Roteiro do primeiro deploy e da rotina de atualização. Plano aprovado pelo Zocca em 2026-11-10
(`/mnt/project-files/planos/m-50-deploy-hostinger.md`). Quem executa é o Zocca: as threads não têm acesso ao
servidor nem ao `.env`.

- Loja: `https://mimoolab.com.br` (build do Vite pelo **Web App** da Hostinger, plano Unlimited/ex-Business).
- API e painel: `https://api.mimoolab.com.br` (painel em `/admin`), Laravel em PHP 8.4, MariaDB 11.8.9.
- Branch publicada: **`producao`**, avançada só pelo Zocca (PR `main → producao` no GitHub). A `main` nunca vai
  ao ar sozinha.
- **Antes da virada para produção (fase 3) a M-51 precisa estar na `producao`**: ela tira a simulação de PIX do
  código de produção.

**Como ler este roteiro.** Cada passo diz o resultado **esperado** e, nos críticos (DNS, SSL, migrations, link do
`public_html`, cron, webhook e virada), **se der errado, faça X**. Cada fase termina com **Voltar atrás**: como
desfazer o que a fase fez. Se um passo falhar e o "se der errado" não resolver, pare ali e mande na thread a saída
do comando (sem senhas nem tokens).

Nos comandos, `<usuário>` é o seu usuário da Hostinger (o que aparece em `/home/<usuário>` no SSH) e
`PHP=/opt/alt/php84/usr/bin/php` (conferido no M-45). Na Hostinger o comando `composer` é a **versão 1**: use
sempre `composer2`, rodado com o PHP 8.4: `$PHP $(which composer2) ...`.

Estrutura no servidor:

```
~/site-mimo/                               clone do repositório (branch producao)
~/site-mimo/backend/.env                   .env de produção (chmod 600; nunca no Git)
~/domains/api.mimoolab.com.br/public_html  -> link para ~/site-mimo/backend/public
~/domains/mimoolab.com.br/public_html      loja (o Web App publica aqui)
~/backups/diario/ e ~/backups/semanal/     backups locais (7 diários + 4 semanais)
~/bin/rclone                               envio dos backups para fora do servidor
```

---

## Fase 0 — Contas e DNS (antes de tudo)

1. **DNS na Hostinger.** **Antes**, anote (print) os servidores DNS que o Registro.br mostra hoje: são o "voltar
   atrás". No Registro.br, em `mimoolab.com.br` → DNS → "Alterar servidores DNS", troque pelos servidores que o
   hPanel mostra em Domínios (normalmente `ns1.dns-parking.com` e `ns2.dns-parking.com`). Propagação: até 24 h.
   - Esperado: `nslookup -type=ns mimoolab.com.br` no PowerShell mostra os dois servidores da Hostinger.
   - Se der errado: depois de 24 h ainda aparecem os antigos → confira no Registro.br se a troca foi salva (ele
     mostra "em transição") e se os nomes estão idênticos aos do hPanel. O Registro.br recusa a troca ("servidor
     não responde pelo domínio") → no hPanel, adicione o domínio em Domínios antes e tente de novo depois de 1 h.
2. **Resend** (conta contato.mimoolab@gmail.com): Domains → Add domain → `mimoolab.com.br`. Copie para o DNS do
   hPanel cada registro que o Resend mostrar (DKIM `resend._domainkey`, MX e SPF do subdomínio `send`). Acrescente
   um DMARC: TXT `_dmarc` = `v=DMARC1; p=none;`. Espere o Resend marcar "Verified". Crie uma API key com
   permissão **Sending access** (ela é a senha do SMTP).
3. **Mercado Pago** (conta da loja): em Suas integrações → sua aplicação, anote as credenciais de **teste** e as
   de **produção** (Public Key e Access Token). Webhooks: URL `https://api.mimoolab.com.br/api/webhooks/mercadopago`,
   evento **Pagamentos**, no modo teste e no modo produção; anote a **assinatura secreta** de cada um.
4. **Melhor Envio** (conta da loja): token do **sandbox** (ensaio) e de **produção** (virada).
5. **Dados da loja:** razão social, CNPJ, cidade/UF (`.env` e `src/config/loja.js`). Sem eles o
   `mimoo:check-production` reprova (M-41).
6. **Gerenciador de senhas** (ex.: Bitwarden): guarde ali o `.env` inteiro, a senha do banco, a do admin, a API
   key do Resend e a senha do backup. Nada disso vai para o Git, para o chat ou para o Drive.

**Voltar atrás (fase 0):** no Registro.br, recoloque os servidores DNS anotados no item 1 (propaga em até 24 h).
No Resend, apague o domínio; no DNS, os registros `resend._domainkey`, `send` e `_dmarc`. As contas do Mercado Pago
e do Melhor Envio podem ficar: sem as credenciais no `.env`, nada é cobrado.

## Fase 1 — Infraestrutura

### 1.1 Sites, PHP e SSL

1. hPanel → Sites → Adicionar site → **`api.mimoolab.com.br`** como site próprio (subdomínio). Em Avançado →
   Configuração do PHP, escolha **8.4**.
2. Loja: hPanel → Sites → Criar site → **Web App** → Importar repositório Git → autorize o GitHub e escolha
   `DaniloZocca/site-mimo`, branch **`producao`**, Node **22**, comando `npm run build`, saída `dist`.
   Variáveis de build:
   ```
   VITE_API_URL=https://api.mimoolab.com.br
   VITE_MERCADOPAGO_PUBLIC_KEY=<Public Key de TESTE>
   ```
   Se `mimoolab.com.br` já existir como site (foi usado nos testes do M-45), a Hostinger pede para removê-lo
   antes. Para onde apontar o domínio no ensaio, veja a fase 2 ("Loja fechada").
3. Segurança → SSL: o certificado gratuito sai sozinho depois do DNS, para os dois sites. Ligue **Forçar HTTPS**
   nos dois.
   - Esperado: `https://api.mimoolab.com.br` e `https://mimoolab.com.br` abrem com cadeado, sem aviso.
   - Se der errado: o SSL fica "pendente" mais de 1 h depois de o DNS propagar → Segurança → SSL → **Reinstalar**
     no site; confira que `nslookup api.mimoolab.com.br` aponta para o IP do hPanel. Aviso de certificado em
     `www` → instale o SSL também para `www.mimoolab.com.br` (o hPanel lista os dois).
4. **`www` → sem `www` pelo hPanel.** Não dá para confirmar que o Web App respeita um `.htaccess` dentro do build,
   então a loja não tem `.htaccess` (M-53): o redirecionamento é feito no hPanel. Domínios → `mimoolab.com.br` →
   **Redirecionamentos** (ou "Redirecionar domínio") → de `www.mimoolab.com.br` para `https://mimoolab.com.br`,
   tipo **301 (permanente)**, guardando o caminho se a opção existir. A API aceita (CORS) só a origem exata da loja,
   então a loja não pode funcionar em `www`.
   - Esperado: `curl.exe -sI https://www.mimoolab.com.br/` → `301` e `location: https://mimoolab.com.br/`.
   - Se der errado: `200` em `www` → o redirecionamento não foi salvo, ou o `www` está apontado como "alias" do
     Web App (remova o alias e crie o redirecionamento). Sem a opção no hPanel → peça ao suporte da Hostinger o
     redirecionamento 301 de `www` para o domínio sem `www`.
5. **CDN desligada na API:** Sites → `api.mimoolab.com.br` → Desempenho → CDN → **Desativar**. Desligue também
   qualquer cache do servidor (Cache Manager/LiteSpeed) no `api.`. Na loja a CDN pode ficar ligada.
   Motivo: a API não confia em proxy; atrás da CDN todos os clientes teriam o IP da CDN e os limites por IP
   (checkout, rastreio, login) valeriam para a loja inteira; e o filtro da CDN pode barrar o webhook.

### 1.2 Banco

hPanel → Bancos de dados → Gerenciamento → novo banco e novo usuário com senha forte (gerada pelo gerenciador).
Anote nome do banco, usuário e senha.

### 1.3 SSH, ferramentas e código

```bash
ssh -p 65002 <usuário>@<IP do hPanel>
PHP=/opt/alt/php84/usr/bin/php
$PHP -v                          # PHP 8.4.27 ou mais novo
composer2 --version              # Composer version 2.x  (o "composer" é o 1.x: não use)
git --version
```

Chave de leitura do repositório (deploy key):

```bash
ssh-keygen -t ed25519 -C "hostinger-mimoo" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

No GitHub: repositório → Settings → Deploy keys → Add deploy key, cole a linha, **sem** marcar "Allow write access".

```bash
git clone -b producao git@github.com:DaniloZocca/site-mimo.git ~/site-mimo
```

### 1.4 `.env` de produção

```bash
cd ~/site-mimo/backend
cp .env.example .env
chmod 600 .env
nano .env
```

Abra o `.env` e deixe **cada chave** como abaixo. O modelo tem todas as chaves do `backend/.env.example` (o teste
`EnvModelDocTest` reprova o PR que acrescentar uma chave lá sem trazê-la para cá) e as que só a produção usa. Nenhum
valor aqui é segredo: onde há `<...>`, o comentário da linha diz de onde vem o valor. Segredos vêm do gerenciador
de senhas e só existem no `.env` do servidor.

<!-- modelo-env:inicio -->
```dotenv
# --- Aplicação ---
APP_NAME="MIMOO LAB"                          # fixo
APP_ENV=staging                               # fase 2 (ensaio) = staging; fase 3 (virada) = production
APP_KEY=                                      # deixe vazio: o "artisan key:generate" do 1.5 preenche; depois copie para o gerenciador
APP_DEBUG=false                               # fixo: true mostraria código e configuração ao visitante
APP_TIMEZONE=UTC                              # fixo (o banco e os logs usam UTC)
APP_URL=https://api.mimoolab.com.br           # fixo: endereço da API
FRONTEND_URL=https://mimoolab.com.br          # endereço da loja; no ensaio pelo domínio temporário: o endereço temporário (https://)
APP_LOCALE=pt_BR                              # fixo
APP_FALLBACK_LOCALE=pt_BR                     # fixo
APP_FAKER_LOCALE=pt_BR                        # fixo (só afeta dados de teste)
APP_MAINTENANCE_DRIVER=file                   # fixo: o "artisan down" do deploy.sh grava em storage/framework
APP_MAINTENANCE_STORE=database                # fixo (só vale com o driver cache)
BCRYPT_ROUNDS=12                              # fixo

# --- Logs ---
LOG_CHANNEL=stack                             # fixo
LOG_STACK=daily                               # fixo: um arquivo por dia
LOG_DAILY_DAYS=14                             # fixo: guarda 14 dias de log
LOG_DEPRECATIONS_CHANNEL=null                 # fixo
LOG_LEVEL=info                                # fixo

# --- Banco (hPanel → Bancos de dados, passo 1.2) ---
DB_CONNECTION=mariadb                         # fixo
DB_HOST=localhost                             # fixo na Hostinger
DB_PORT=3306                                  # fixo
DB_DATABASE=<nome do banco>                   # hPanel → Bancos de dados (ex.: u123456789_mimoo)
DB_USERNAME=<usuário do banco>                # hPanel → Bancos de dados
DB_PASSWORD=<senha do banco>                  # SEGREDO: gerenciador de senhas (criada no 1.2)

# --- Sessão, cookies e CORS ---
SESSION_DRIVER=database                       # fixo
SESSION_LIFETIME=120                          # fixo (minutos)
SESSION_ENCRYPT=false                         # fixo
SESSION_PATH=/                                # fixo
SESSION_DOMAIN=.mimoolab.com.br               # fixo: loja e API dividem o cookie do login de cliente
SESSION_SECURE_COOKIE=true                    # fixo: cookie só por HTTPS
SESSION_SAME_SITE=lax                         # fixo
CORS_ALLOWED_ORIGINS=https://mimoolab.com.br  # endereço da loja; no ensaio pelo domínio temporário: o endereço temporário (https://)

# --- Infraestrutura (sem Redis, Memcached, S3 nem broadcast na Hostinger) ---
BROADCAST_CONNECTION=log                      # fixo (não usado)
FILESYSTEM_DISK=local                         # fixo: imagens em storage/app/public
QUEUE_CONNECTION=database                     # fixo: o cron do 1.7 processa a fila
CACHE_STORE=database                          # fixo
CACHE_PREFIX=                                 # fixo (vazio)
MEMCACHED_HOST=127.0.0.1                      # fixo (não usado)
REDIS_CLIENT=phpredis                         # fixo (não usado)
REDIS_HOST=127.0.0.1                          # fixo (não usado)
REDIS_PASSWORD=null                           # fixo (não usado)
REDIS_PORT=6379                               # fixo (não usado)
AWS_ACCESS_KEY_ID=                            # vazio (não usado)
AWS_SECRET_ACCESS_KEY=                        # vazio (não usado)
AWS_DEFAULT_REGION=us-east-1                  # fixo (não usado)
AWS_BUCKET=                                   # vazio (não usado)
AWS_USE_PATH_STYLE_ENDPOINT=false             # fixo (não usado)

# --- E-mail (Resend, fase 0 item 2) ---
MAIL_MAILER=smtp                              # fixo
MAIL_HOST=smtp.resend.com                     # fixo
MAIL_PORT=465                                 # fixo
MAIL_ENCRYPTION=ssl                           # fixo
MAIL_USERNAME=resend                          # fixo (é a palavra "resend" mesmo)
MAIL_PASSWORD=<API key do Resend>             # SEGREDO: Resend → API Keys (Sending access), guardada no gerenciador
MAIL_FROM_ADDRESS=pedidos@mimoolab.com.br     # fixo (domínio verificado no Resend)
MAIL_FROM_NAME="MIMOO LAB"                    # fixo
MAIL_REPLY_TO_ADDRESS=contato.mimoolab@gmail.com  # fixo: as respostas dos clientes chegam aqui
MAIL_REPLY_TO_NAME="MIMOO LAB"                # fixo

# --- Mercado Pago (fase 0 item 3) ---
MERCADOPAGO_ACCESS_TOKEN=<Access Token>       # SEGREDO: Suas integrações → credenciais de TESTE (fase 2) / de PRODUÇÃO (fase 3)
MERCADOPAGO_PUBLIC_KEY=<Public Key>           # Suas integrações → a de TESTE (fase 2) / a de PRODUÇÃO (fase 3); a mesma da loja
MERCADOPAGO_WEBHOOK_SECRET=<assinatura>       # SEGREDO: Webhooks → assinatura secreta do modo teste (fase 2) / produção (fase 3)
MERCADOPAGO_BASE_URL=https://api.mercadopago.com  # fixo

# --- Melhor Envio (fase 0 item 4) ---
MELHOR_ENVIO_URL=https://sandbox.melhorenvio.com.br  # fase 2; fase 3: https://melhorenvio.com.br
MELHOR_ENVIO_TOKEN=<token>                    # SEGREDO: Melhor Envio → token do sandbox (fase 2) / de produção (fase 3)
SHIPPING_ORIGIN_CEP=<CEP>                     # CEP de onde a loja despacha, só números (dado da loja)

# --- Dados da loja (fase 0 item 5; Política de Privacidade e rodapé dos e-mails) ---
LOJA_RAZAO_SOCIAL="<razão social>"            # cartão CNPJ da loja
LOJA_CNPJ=<CNPJ>                              # cartão CNPJ da loja
LOJA_CIDADE_UF="<Cidade / UF>"                # cartão CNPJ da loja

# --- Admin do painel (só durante o 1.5) ---
ADMIN_EMAIL=<e-mail do admin do painel>       # o e-mail com que você vai entrar no /admin
ADMIN_PASSWORD=<senha forte>                  # SEGREDO: gerenciador; APAGUE esta linha logo depois do seeder (1.5)

# --- Vite (sobra do Laravel; a loja não usa este arquivo) ---
VITE_APP_NAME="${APP_NAME}"                   # fixo
```
<!-- modelo-env:fim -->

Sobre `SESSION_DOMAIN` e `CORS_ALLOWED_ORIGINS` no ensaio pelo domínio temporário: o login de cliente só
funciona com loja e API sob o mesmo domínio. No domínio temporário o cadastro/login dá 419 (o
`mimoo:check-production` avisa); teste a conta de cliente depois de ligar `mimoolab.com.br` (fase 3, item 4).

### 1.5 Instalação

```bash
cd ~/site-mimo/backend
PHP=/opt/alt/php84/usr/bin/php
$PHP $(which composer2) install --no-dev --optimize-autoloader --no-interaction
$PHP artisan key:generate --force
$PHP artisan migrate --force
$PHP artisan db:seed --class=AdminUserSeeder --force     # antes do config:cache (o seeder lê o .env direto)
nano .env                                                 # apague a linha ADMIN_PASSWORD
$PHP artisan storage:link
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
$PHP artisan mimoo:check-production
```

- Esperado no `migrate --force`: uma linha `DONE` por migration, sem erro.
- Se der errado no `migrate`: `Access denied` ou `Unknown database` → confira `DB_DATABASE`, `DB_USERNAME` e
  `DB_PASSWORD` no `.env` contra o hPanel (o usuário precisa estar ligado ao banco, com todos os privilégios). Erro
  no meio das migrations → `$PHP artisan migrate:status` mostra onde parou; como o banco ainda está vazio, corrija e
  rode `$PHP artisan migrate:fresh --force` (**só aqui**, na instalação: apaga tudo). Mande a saída na thread.
- **Nunca** rode `db:seed` sem `--class`: o seeder completo cria produtos de demonstração.
- Copie a `APP_KEY` que o `key:generate` gravou no `.env` para o gerenciador de senhas.
- No ensaio o `mimoo:check-production` reprova o `APP_ENV`, o token de teste do Mercado Pago e o Melhor Envio do
  sandbox: é o esperado nesta fase. Qualquer outro item da lista precisa ser corrigido.

### 1.6 Publicar a API (link do `public_html`)

```bash
ls -la ~/domains/api.mimoolab.com.br/public_html      # só o default.php da Hostinger (ou vazio)
rm -r ~/domains/api.mimoolab.com.br/public_html
ln -s ~/site-mimo/backend/public ~/domains/api.mimoolab.com.br/public_html
```

- Esperado: `ls -la ~/domains/api.mimoolab.com.br/` mostra `public_html -> /home/<usuário>/site-mimo/backend/public`
  e `https://api.mimoolab.com.br/up` responde 200.
- Se der errado: `403`/`404` → confira o link com `ls -la` (o destino precisa existir); recrie com
  `rm ~/domains/api.mimoolab.com.br/public_html` (apaga só o link) e o `ln -s` de novo. Página padrão da Hostinger
  → o `rm -r` não rodou e a pasta antiga continua lá. `500` → `tail -50 ~/site-mimo/backend/storage/logs/laravel-*.log`
  e mande o `error_ref` na thread.
- Voltar atrás: `rm ~/domains/api.mimoolab.com.br/public_html && mkdir ~/domains/api.mimoolab.com.br/public_html`.

### 1.7 Cron (fila de e-mails)

hPanel → Avançado → Cron Jobs → **Personalizado**, a cada minuto (`* * * * *`):

```
/opt/alt/php84/usr/bin/php /home/<usuário>/site-mimo/backend/artisan schedule:run >> /dev/null 2>&1
```

- Esperado, depois de 2 minutos: `$PHP artisan schedule:list` mostra o `queue:work` e o `mimoo:check-production`
  não reclama de job parado.
- Se der errado: job parado ou e-mail que não sai → rode a mesma linha do cron à mão no SSH (sem o `>> /dev/null`)
  e veja o erro; confira o caminho absoluto `/home/<usuário>/...` (o cron não entende `~`) e o PHP
  `/opt/alt/php84/usr/bin/php`. O hPanel mostra a saída do cron em Cron Jobs → "Ver saída", se existir.
- Voltar atrás: apague o cron no hPanel; os e-mails ficam na fila (tabela `jobs`) e saem quando ele voltar.

### 1.8 OPcache e `expose_php`

- OPcache: fica ligado. Confira que ele relê os arquivos alterados (senão o deploy não "pega"):
  ```bash
  echo '<?php echo "validate_timestamps=", ini_get("opcache.validate_timestamps"), " revalidate_freq=", ini_get("opcache.revalidate_freq");' > ~/site-mimo/backend/public/opcache-check.php
  ```
  Abra `https://api.mimoolab.com.br/opcache-check.php`: precisa mostrar `validate_timestamps=1`. **Apague em
  seguida:** `rm ~/site-mimo/backend/public/opcache-check.php`. Se mostrar `0`, me avise: o `deploy.sh` vai precisar
  reiniciar o PHP pelo hPanel a cada deploy.
- `expose_php`: em Avançado → Configuração do PHP → Opções do PHP, desligue `expose_php` se a opção existir. Se
  não existir, o `backend/public/.htaccess` já remove o cabeçalho `X-Powered-By` (conferido no 1.9).

### 1.9 Testes de infraestrutura (PowerShell do seu PC)

| Teste | Comando | Esperado |
|---|---|---|
| API no ar | `curl.exe -sI https://api.mimoolab.com.br/up` | `HTTP/1.1 200` (ou `HTTP/2 200`), `strict-transport-security`, **sem** `x-powered-by`, **sem** `server: hcdn`, **sem** `x-hcdn-*` e **sem** `x-litespeed-cache` |
| Arquivos com ponto | `curl.exe -s -o NUL -w "%{http_code}" https://api.mimoolab.com.br/.user.ini` e o mesmo com `/.env`, `/.htaccess` e `/.git/config` | `403` ou `404` nos quatro (se algum der `200`, tire a API do ar com `$PHP artisan down` e mande na thread) |
| CORS da loja | `curl.exe -sI -H "Origin: https://mimoolab.com.br" https://api.mimoolab.com.br/api/products` | `access-control-allow-origin: https://mimoolab.com.br` |
| CORS de outro site | o mesmo com `-H "Origin: https://exemplo.com"` | sem `access-control-allow-origin` |
| Painel | abrir `https://api.mimoolab.com.br/admin` | tela de login; o admin entra |
| Imagens | cadastrar um produto com foto no painel e abrir a foto | abre em `https://api.mimoolab.com.br/storage/products/...` |
| `www` | `curl.exe -sI https://www.mimoolab.com.br/` | `301` para `https://mimoolab.com.br/` |

**IP real** (prova de que a CDN não está na frente):

```bash
echo '<?php header("Content-Type: text/plain"); echo $_SERVER["REMOTE_ADDR"];' > ~/site-mimo/backend/public/ip-check.php
```

Abra `https://api.mimoolab.com.br/ip-check.php` e compare com o seu IP (pesquise "qual é meu ip"). Precisam ser
iguais. **Apague em seguida:** `rm ~/site-mimo/backend/public/ip-check.php` (o `deploy.sh` recusa rodar com esse
arquivo lá). Se for diferente, a CDN (ou um proxy) está na frente da API: não siga para a fase 2.

**Voltar atrás (fase 1):** nada está aberto ao público ainda. Para recomeçar do zero: apague o cron (1.7), desfaça
o link (1.6), apague o banco e o usuário no hPanel (1.2), `rm -rf ~/site-mimo` no SSH, remova a deploy key no
GitHub (Settings → Deploy keys) e apague os dois sites no hPanel. O `.env` guardado no gerenciador de senhas
permite refazer o 1.4 sem gerar segredos novos (a `APP_KEY` tem de ser a mesma se o banco for mantido).

## Fase 2 — Ensaio com credenciais de teste (loja fechada)

`.env` como na fase 1 (`APP_ENV=staging`, credenciais de **teste**). Em `production` um pagamento de teste vai
para "Pagamento em revisão" (a API só libera pagamento real em produção); por isso o ensaio é em `staging`.

### Loja fechada: duas opções

- **Opção 1 — senha na loja.** Se o site da loja tiver Avançado → **Proteger pasta com senha** (ou "Password
  Protect Directories"), proteja o `public_html` da loja. `mimoolab.com.br` já pode apontar para a loja.
- **Opção 2 — domínio temporário.** Se o Web App não tiver essa opção, faça o ensaio no **domínio temporário** que
  o hPanel mostra para a loja (Sites → Detalhes) e só ligue `mimoolab.com.br` à loja na virada (fase 3). No
  ensaio, `FRONTEND_URL` e `CORS_ALLOWED_ORIGINS` recebem o endereço temporário (com `https://`) e a conta de
  cliente fica para depois da virada (item 6 abaixo).

Depois de mudar o `.env`: `$PHP artisan config:cache`.

### Checklist do ensaio

Para cada item: o que fazer → o que conferir. Com algum item falhando, não siga para a fase 3.

1. **Webhook teste B** — Mercado Pago → Webhooks (modo teste) → Simular notificação, evento `payment`, na URL da API
   → a resposta mostrada é **422** "Falha na verificação reversa do pagamento" (a assinatura passou e a API foi
   consultar um pagamento inventado). Se der errado: `401` = assinatura secreta errada no `.env` (confira que é a
   do modo **teste** e rode `$PHP artisan config:cache`); `403` sem corpo do Laravel = bloqueio da Hostinger ou
   CDN ligada no `api.` (refaça o 1.1 item 5 e mande na thread o que apareceu); `503` = API em manutenção
   (`$PHP artisan up`); `500` = mande o `error_ref` do log.
2. **PIX** com um comprador de teste do Mercado Pago → pedido aprovado vira "Na fila" no painel e o e-mail
   "pedido confirmado" chega em até ~1 min, com Reply-To para contato.mimoolab@gmail.com.
3. **Cartão de teste aprovado** (resposta na hora) e um **recusado** (mensagem de erro na loja, pedido pendente).
4. **Painel**: iniciar impressão → acabamento → expedir com código de rastreio → entregue. Chegam os e-mails de
   impressão e de envio.
5. **Rastreio** na loja pelo número do pedido + CPF, e pelo botão "Acompanhar" do e-mail (abre a loja).
6. **Conta de cliente** (só com loja e API em `mimoolab.com.br`): cadastro, e-mail de confirmação, link abre a
   loja e confirma, "Meus pedidos", "Esqueci minha senha"; cadastrar de novo o mesmo e-mail mostra a mesma
   mensagem e chega o aviso "Alguém tentou criar uma conta com seu e-mail" (M-44).
7. **Frete**: no phpMyAdmin, `SELECT order_number, shipping_price_source FROM orders ORDER BY created_at DESC LIMIT 1;`
   mostra `melhor_envio` (não `fallback`).
8. **Log sem dados pessoais**: `grep -c "@" ~/site-mimo/backend/storage/logs/laravel-*.log` não deve achar
   e-mail de cliente (só o que você mesmo digitou em teste, se algum); erro inesperado aparece com `error_ref`.
9. **Backup**: instale o rclone e rode o backup uma vez (seção "Backup" abaixo); confira os arquivos no Google
   Drive (com nomes embaralhados, é a criptografia) e **restaure** no MariaDB local
   (`docs/ambiente-local-mariadb.md`): `rclone copy mimoo-backup-crypt:diario/<arquivo> .` no PC, `gunzip` e
   importar. Só há dados de teste nesta fase.
10. **Monitoramento**: `$PHP artisan down` → o alerta do UptimeRobot chega nos dois e-mails em até ~5 min →
    `$PHP artisan up` → chega o aviso de volta.
11. **Atualização**: avance a `producao` com qualquer mudança pequena e rode o `deploy.sh` (seção "Atualizar").
    Em `staging` o `mimoo:check-production` reprova de propósito (APP_ENV e credenciais de teste), então o deploy
    termina com "ERRO no passo: mimoo:check-production" e a API **fica em manutenção**: é o comportamento certo.
    Confira que só esses itens aparecem e tire da manutenção à mão: `$PHP artisan up`. Na produção isso não
    acontece: o check passa e o script sai da manutenção sozinho.

No fim do ensaio, limpe os dados de teste (ainda não há nada real):

```bash
$PHP artisan migrate:fresh --force
$PHP artisan config:clear
nano .env                                   # recoloque ADMIN_PASSWORD
$PHP artisan db:seed --class=AdminUserSeeder --force
nano .env                                   # apague ADMIN_PASSWORD de novo
$PHP artisan config:cache
```

e recadastre o catálogo pelo painel. As imagens de teste em `storage/app/public/products` podem ser apagadas.

**Voltar atrás (fase 2):** a loja segue fechada (senha ou domínio temporário) e só há dados de teste. Se o ensaio
reprovar num item, corrija e repita o item; para descartar tudo, use a limpeza acima. Os backups de teste no Google
Drive podem ser apagados (`~/bin/rclone purge mimoo-backup-crypt:` apaga só a pasta criptografada).

## Fase 3 — Virar produção

Pré-requisito: **M-51 na `producao`** (sem simulação de PIX no código de produção).

1. **`.env` da API** com as credenciais de **produção**:
   `APP_ENV=production`, `MERCADOPAGO_ACCESS_TOKEN`/`MERCADOPAGO_PUBLIC_KEY` de produção,
   `MERCADOPAGO_WEBHOOK_SECRET` do webhook de **produção**, `MELHOR_ENVIO_URL=https://melhorenvio.com.br`,
   `MELHOR_ENVIO_TOKEN` de produção; `FRONTEND_URL` e `CORS_ALLOWED_ORIGINS` = `https://mimoolab.com.br`.
   Depois:
   ```bash
   $PHP artisan config:cache
   $PHP artisan mimoo:check-production
   ```
   Precisa terminar com **"Configuração de produção ok."** (cole a saída na thread).
   Se der errado: o check lista só os **nomes** das chaves com problema; corrija no `.env`, rode `config:cache` e o
   check de novo. Não siga para o item 2 com o check reprovado.
2. **Loja com a chave pública de PRODUÇÃO:** no Web App, troque a variável de build
   `VITE_MERCADOPAGO_PUBLIC_KEY` pela **Public Key de produção** e publique de novo (Redeploy). Chave de teste na
   loja com token de produção na API = cartão falhando. Confira na loja que o formulário de cartão abre e
   reconhece a bandeira de um cartão real (sem pagar).
   Se der errado: o formulário não abre ou dá "public key inválida" → a variável foi trocada mas não houve
   Redeploy, ou a chave é de outra aplicação; confira no Mercado Pago e publique de novo.
3. **Consultas só leitura** no phpMyAdmin, depois de recadastrar o catálogo:
   `/mnt/project-files/consultas/produtos-sem-cor-ou-tamanho.sql` (produto sem cor/tamanho vinculado dá 422 no
   checkout, M-03) e `docs/consultas/m-29-pecas-sem-preco.sql` (peça/tamanho abaixo de R$ 0,01 dá 422, M-29).
   Esperado: nenhuma linha. Se aparecer alguma, corrija o produto no painel antes de abrir.
4. Se o ensaio foi no domínio temporário (opção 2): ligue `mimoolab.com.br` ao Web App da loja, espere o SSL e
   repita os testes da conta de cliente (fase 2, item 6) e do `www` (1.9).
5. **Compra real pequena**: o produto mais barato, pago com PIX por você → "Na fila" no painel e e-mail de
   confirmação. Depois, **estorno** pelo painel do Mercado Pago e o pedido tratado como no
   `docs/runbook-liberar-pedido-em-revisao.md` / cancelamento manual (pedido pago não se cancela pelo painel).
   Uma compra com cartão real pequena prova o item 2.
   Se der errado: pedido fica "Aguardando pagamento" depois do PIX pago → o webhook de **produção** não chegou ou
   foi recusado: Mercado Pago → Webhooks (modo produção) → histórico de notificações mostra o código da resposta
   (401 = `MERCADOPAGO_WEBHOOK_SECRET` ainda é o de teste). Pedido em "Pagamento em revisão" → siga o
   `docs/runbook-liberar-pedido-em-revisao.md`. Não abra a loja até uma compra real chegar em "Na fila".
6. Tire a senha da loja (opção 1). **Loja aberta.**

**Voltar atrás (fase 3):** feche a loja de novo e pare a API sem perder nada:
1. `$PHP artisan down --retry=60` (a loja não consegue criar pedido; o Mercado Pago reenvia os webhooks depois).
2. Recoloque a senha da loja (opção 1) ou tire `mimoolab.com.br` do Web App (opção 2).
3. **Não** volte o `.env` para as credenciais de teste se já houver pedido real: os pagamentos reais precisam do
   token de produção para serem conferidos. Pedidos reais pagos são tratados um a um (estorno pelo Mercado Pago).
4. Corrija, rode `$PHP artisan mimoo:check-production` e `$PHP artisan up`.

## Fase 4 — Rotina

### Atualizar (cada deploy)

1. No GitHub, abra um PR `main → producao` e faça o merge (o Web App publica a loja sozinho nesse momento).
2. No SSH:
   ```bash
   bash ~/site-mimo/deploy/hostinger/deploy.sh
   ```
   O script recusa rodar se o clone não estiver na branch `producao` ou se houver arquivo alterado ou novo no
   repositório (`git status`), sem mexer em nada. Depois mostra a versão no ar e a que vai entrar, pede
   confirmação, põe a API em manutenção (o webhook recebe 503 e o Mercado Pago reenvia), atualiza o código só por
   avanço (fast-forward), roda `composer2 install --no-dev`, `migrate --force`, refaz os caches, roda o
   `mimoo:check-production`, reinicia o worker e **só então** sai da manutenção. Os arquivos que ele gera
   (caches com a configuração) ficam só para o seu usuário (`umask 077`).
   - Esperado: termina com `Deploy ok: <commit> <mensagem>`.
   - Se der errado: o script mostra `ERRO no passo: <passo>`, deixa a API **em manutenção** e imprime os comandos
     para voltar (rodar de novo depois de corrigir, ou voltar para a versão anterior). Ele nunca sai da
     manutenção sozinho depois de um erro, nem com o check reprovado.
3. Não edite arquivos do repositório no servidor e apague os arquivos de teste (`opcache-check.php`,
   `ip-check.php`) depois de usar: o script recusa rodar com o `git status` sujo.

### Voltar atrás (fase 4): voltar uma versão

```bash
bash ~/site-mimo/deploy/hostinger/deploy.sh <commit anterior>   # o deploy.sh mostra qual era
```

Só aceita um commit que já esteve na `producao`. Migration nova não é desfeita por isso: se a versão que falhou
trouxe migration, leia o PR dela antes de voltar. Se ele disser que o `migrate:rollback` é seguro, rode
`$PHP artisan migrate:rollback --step=<n.º de migrations novas>` **antes** de voltar o código; se não disser, volte
só o código e mande na thread. Na loja, o Web App tem o histórico de deploys para republicar o anterior (ou faça
um PR revertendo na `producao`).

### Backup fora do servidor (diário, criptografado)

Instalação, uma vez:

1. **rclone no servidor**:
   ```bash
   mkdir -p ~/bin ~/backups && cd ~/bin
   curl -fsSLO https://downloads.rclone.org/rclone-current-linux-amd64.zip
   unzip -j rclone-current-linux-amd64.zip '*/rclone' && rm rclone-current-linux-amd64.zip
   chmod 700 rclone && ./rclone version
   ```
   Se o download ou o `rclone version` falhar (compartilhado bloqueando), vá para o **plano B** abaixo.
2. **Remotos** (no seu PC, com o rclone para Windows, porque o Google pede login no navegador):
   `rclone config` → `n` → nome `mimoo-gdrive` → tipo `drive` → escopo `drive.file` → login na conta
   contato.mimoolab@gmail.com (com 2FA). Depois outro remoto: nome `mimoo-backup-crypt`, tipo `crypt`, remoto
   `mimoo-gdrive:mimoo-backups`, criptografar nomes = `standard`, senha e "salt" **gerados pelo gerenciador de
   senhas e guardados nele** (sem eles o backup não abre). Copie o arquivo de configuração para o servidor
   (`rclone config file` mostra onde está) em `~/.config/rclone/rclone.conf` e rode
   `chmod 600 ~/.config/rclone/rclone.conf`.
3. **Senha do banco para o dump**, em `~/.my.cnf`:
   ```
   [client]
   user=<usuário do banco>
   password=<senha do banco>
   host=localhost
   ```
   `chmod 600 ~/.my.cnf`
4. **Nome do banco** em `~/.mimoo-backup.conf` (`chmod 600`): `DB_NAME="<nome do banco>"`.
5. Teste: `bash ~/site-mimo/deploy/hostinger/backup.sh` → uma linha `... ok backup ...`;
   `~/bin/rclone ls mimoo-backup-crypt:diario/` lista os dois arquivos.
6. Cron Personalizado, todo dia (o horário do cron da Hostinger é o do servidor; confira com `date` e ajuste para
   cair de madrugada no Brasil):
   ```
   0 6 * * * bash /home/<usuário>/site-mimo/deploy/hostinger/backup.sh >> /home/<usuário>/backups/backup.log 2>&1
   ```

O script guarda o banco (`mariadb-dump --single-transaction`) e as imagens dos produtos: 7 diários + 4 semanais
(domingo) no servidor (`~/backups/diario/` e `~/backups/semanal/`) e fora dele. O backup tem dados pessoais (LGPD):
só sai do servidor criptografado. Cada execução escreve **uma linha** no `~/backups/backup.log`:
`<data> ok backup banco=... imagens=... semanal=sim|nao ping=...` ou `<data> ERRO backup passo="..." ...`, com a
mensagem da ferramenta em `~/backups/ultimo-erro.txt`. Se só o envio falhar, o backup local do dia fica. Olhe o log
uma vez por semana (ou ponha uma URL do healthchecks.io em `HEALTHCHECK_URL` no `~/.mimoo-backup.conf` para ser
avisado se ele parar).

**Plano B** (rclone não roda): os backups da própria Hostinger (Arquivos → Backups) continuam; uma vez por semana
baixe o do banco pelo hPanel para o PC, guarde num disco criptografado (BitLocker) e apague os antigos.

**Restaurar**: baixar com `rclone copy mimoo-backup-crypt:diario/<arquivo> .`, `gunzip`, importar no banco
(`mariadb <banco> < arquivo.sql`) e descompactar as imagens em `storage/app/public`.

### Monitoramento de disponibilidade

UptimeRobot (plano grátis, checagem a cada 5 min), alertas para o seu e-mail pessoal e para
contato.mimoolab@gmail.com:

- `https://api.mimoolab.com.br/up` (HTTP, espera 200);
- `https://mimoolab.com.br` (HTTP, espera 200).

Fila parada (e-mails não saindo) ainda depende do `mimoo:check-production` à mão até o M-33.

## Depois de cada deploy

- `mimoo:check-production` com "Configuração de produção ok.".
- Loja abre, um produto abre, o frete calcula.
- `~/site-mimo/backend/storage/logs/laravel-<data>.log` sem erro novo.
