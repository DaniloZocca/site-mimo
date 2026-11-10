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

Nos comandos, `<usuário>` é o seu usuário da Hostinger (o que aparece em `/home/<usuário>` no SSH) e
`PHP=/opt/alt/php84/usr/bin/php` (conferido no M-45). Na Hostinger o comando `composer` é a **versão 1**: use
sempre `composer2`, rodado com o PHP 8.4: `$PHP $(which composer2) ...`.

Estrutura no servidor:

```
~/site-mimo/                               clone do repositório (branch producao)
~/site-mimo/backend/.env                   .env de produção (chmod 600; nunca no Git)
~/domains/api.mimoolab.com.br/public_html  -> link para ~/site-mimo/backend/public
~/domains/mimoolab.com.br/public_html      loja (o Web App publica aqui)
~/backups/                                 backups locais (7 dias)
~/bin/rclone                               envio dos backups para fora do servidor
```

---

## Fase 0 — Contas e DNS (antes de tudo)

1. **DNS na Hostinger.** No Registro.br, em `mimoolab.com.br` → DNS → "Alterar servidores DNS", troque pelos
   servidores que o hPanel mostra em Domínios (normalmente `ns1.dns-parking.com` e `ns2.dns-parking.com`).
   Propagação: até 24 h. Conferir: `nslookup -type=ns mimoolab.com.br` no PowerShell.
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
   nos dois. `www.mimoolab.com.br` redireciona para `https://mimoolab.com.br` pelo `public/.htaccess` da loja.
4. **CDN desligada na API:** Sites → `api.mimoolab.com.br` → Desempenho → CDN → **Desativar**. Desligue também
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

Troque/acrescente estas linhas (valores entre `< >` vêm do gerenciador de senhas; não cole segredos em lugar
nenhum além do `.env`):

```dotenv
APP_NAME="MIMOO LAB"
APP_ENV=staging                 # fase 2 (ensaio). Na fase 3: production
APP_DEBUG=false
APP_URL=https://api.mimoolab.com.br
FRONTEND_URL=https://mimoolab.com.br        # no ensaio pelo domínio temporário: o endereço temporário
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<nome do banco>
DB_USERNAME=<usuário do banco>
DB_PASSWORD=<senha do banco>

SESSION_DRIVER=database
SESSION_DOMAIN=.mimoolab.com.br
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CORS_ALLOWED_ORIGINS=https://mimoolab.com.br   # no ensaio pelo domínio temporário: o endereço temporário

QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=resend
MAIL_PASSWORD=<API key do Resend>
MAIL_FROM_ADDRESS=pedidos@mimoolab.com.br
MAIL_FROM_NAME="MIMOO LAB"
MAIL_REPLY_TO_ADDRESS=contato.mimoolab@gmail.com
MAIL_REPLY_TO_NAME="MIMOO LAB"

MERCADOPAGO_ACCESS_TOKEN=<Access Token de TESTE>      # fase 3: de produção
MERCADOPAGO_PUBLIC_KEY=<Public Key de TESTE>          # fase 3: de produção
MERCADOPAGO_WEBHOOK_SECRET=<assinatura do webhook de teste>   # fase 3: a do webhook de produção

MELHOR_ENVIO_URL=https://sandbox.melhorenvio.com.br   # fase 3: https://melhorenvio.com.br
MELHOR_ENVIO_TOKEN=<token do sandbox>                 # fase 3: de produção
SHIPPING_ORIGIN_CEP=<CEP de onde a loja despacha, só números>

LOJA_RAZAO_SOCIAL="<razão social>"
LOJA_CNPJ=<CNPJ>
LOJA_CIDADE_UF="<Cidade / UF>"

ADMIN_EMAIL=<e-mail do admin do painel>
ADMIN_PASSWORD=<senha forte>          # apagar depois do passo 1.5
```

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

### 1.7 Cron (fila de e-mails)

hPanel → Avançado → Cron Jobs → **Personalizado**, a cada minuto (`* * * * *`):

```
/opt/alt/php84/usr/bin/php /home/<usuário>/site-mimo/backend/artisan schedule:run >> /dev/null 2>&1
```

Conferir depois de 2 minutos: `$PHP artisan schedule:list` mostra o `queue:work` e o `mimoo:check-production`
mostra "Fila: nenhum job parado".

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
| Arquivos com ponto | `curl.exe -s -o NUL -w "%{http_code}" https://api.mimoolab.com.br/.user.ini` e o mesmo com `/.env` e `/.htaccess` | `403` ou `404` nos três |
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
iguais. **Apague em seguida:** `rm ~/site-mimo/backend/public/ip-check.php`. Se for diferente, a CDN (ou um
proxy) está na frente da API: não siga para a fase 2.

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
   consultar um pagamento inventado). `401` = assinatura secreta errada no `.env`; `403` sem corpo do Laravel = bloqueio
   da Hostinger (me mande o que apareceu).
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
2. **Loja com a chave pública de PRODUÇÃO:** no Web App, troque a variável de build
   `VITE_MERCADOPAGO_PUBLIC_KEY` pela **Public Key de produção** e publique de novo (Redeploy). Chave de teste na
   loja com token de produção na API = cartão falhando. Confira na loja que o formulário de cartão abre e
   reconhece a bandeira de um cartão real (sem pagar).
3. **Consultas só leitura** de "Antes do deploy" no `docs/coordenacao.md`
   (`produtos-sem-cor-ou-tamanho.sql`, `m-29-pecas-sem-preco.sql`) e correção no painel do que aparecer.
4. Se o ensaio foi no domínio temporário (opção 2): ligue `mimoolab.com.br` ao Web App da loja, espere o SSL e
   repita os testes da conta de cliente (fase 2, item 6) e do `www` (1.9).
5. **Compra real pequena**: o produto mais barato, pago com PIX por você → "Na fila" no painel e e-mail de
   confirmação. Depois, **estorno** pelo painel do Mercado Pago e o pedido tratado como no
   `docs/runbook-liberar-pedido-em-revisao.md` / cancelamento manual (pedido pago não se cancela pelo painel).
   Uma compra com cartão real pequena prova o item 2.
6. Tire a senha da loja (opção 1). **Loja aberta.**

## Fase 4 — Rotina

### Atualizar (cada deploy)

1. No GitHub, abra um PR `main → producao` e faça o merge (o Web App publica a loja sozinho nesse momento).
2. No SSH:
   ```bash
   bash ~/site-mimo/deploy/hostinger/deploy.sh
   ```
   O script mostra a versão no ar e a que vai entrar, pede confirmação, põe a API em manutenção (o webhook recebe
   503 e o Mercado Pago reenvia), atualiza o código só por avanço (fast-forward), roda `composer2 install
   --no-dev`, `migrate --force`, refaz os caches, reinicia o worker, sai da manutenção e roda o
   `mimoo:check-production`. Se um passo falhar, a API fica em manutenção e o script mostra como voltar.
3. Não edite arquivos do repositório no servidor: o script recusa rodar com o `git status` sujo.

### Voltar uma versão

```bash
bash ~/site-mimo/deploy/hostinger/deploy.sh <commit anterior>   # o deploy.sh mostra qual era
```

Migration nova não é desfeita por isso: só com `artisan migrate:rollback`, se o PR daquela versão disser que é
seguro. Na loja, o Web App tem o histórico de deploys para republicar o anterior.

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
5. Teste: `bash ~/site-mimo/deploy/hostinger/backup.sh` → termina com "fim"; `~/bin/rclone ls mimoo-backup-crypt:diario/`
   lista os dois arquivos.
6. Cron Personalizado, todo dia (o horário do cron da Hostinger é o do servidor; confira com `date` e ajuste para
   cair de madrugada no Brasil):
   ```
   0 6 * * * bash /home/<usuário>/site-mimo/deploy/hostinger/backup.sh >> /home/<usuário>/backups/backup.log 2>&1
   ```

O script guarda o banco (`mariadb-dump --single-transaction`) e as imagens dos produtos, 7 dias no servidor e, fora
dele, 7 diários + 4 semanais (domingo). O backup tem dados pessoais (LGPD): só sai do servidor criptografado. Olhe o
`~/backups/backup.log` uma vez por semana (ou ponha uma URL do healthchecks.io em `HEALTHCHECK_URL` no
`~/.mimoo-backup.conf` para ser avisado se ele parar).

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
