# Ambiente local com MariaDB 11.8.9 (Windows + Docker Desktop)

M-45: a produção (Hostinger) usa **MariaDB 11.8.9**. O banco local precisa ser a mesma versão, senão diferenças
de comportamento só aparecem em produção. O Postgres local deixa de ser usado.

## 1. Instalar o Docker Desktop (uma vez)

1. Baixe em <https://www.docker.com/products/docker-desktop/> e instale com a opção **WSL 2** marcada.
2. Reinicie o Windows se ele pedir e abra o Docker Desktop. Espere aparecer "Engine running" no rodapé.

## 2. Subir o MariaDB (uma vez)

No PowerShell (qualquer pasta):

```powershell
docker run -d --name mimoo-mariadb --restart unless-stopped `
  -e MARIADB_ROOT_PASSWORD=root-local `
  -e MARIADB_DATABASE=mimoo `
  -e MARIADB_USER=mimoo `
  -e MARIADB_PASSWORD=mimoo-local `
  -p 3306:3306 `
  -v mimoo-mariadb-dados:/var/lib/mysql `
  mariadb:11.8.9
```

- `--restart unless-stopped`: o banco volta sozinho quando o Docker Desktop abre.
- `-v mimoo-mariadb-dados:...`: os dados ficam num volume e não somem ao reiniciar.
- Se a porta 3306 já estiver ocupada (XAMPP, Laragon ou outro MySQL), pare o outro ou troque para `-p 3307:3306` e
  use `DB_PORT=3307` abaixo.

Conferir a versão:

```powershell
docker exec mimoo-mariadb mariadb -umimoo -pmimoo-local -e "SELECT VERSION();"
```

Deve mostrar `11.8.9-MariaDB...`.

## 3. Apontar o backend para o MariaDB

No `backend\.env` (você edita à mão; o Claude não abre esse arquivo), troque as linhas do banco por:

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mimoo
DB_USERNAME=mimoo
DB_PASSWORD=mimoo-local
```

Não defina `DB_COLLATION`: o padrão (`utf8mb4_uca1400_ai_ci`) é o certo para o 11.8, e as colunas de token e e-mail
ficam em `utf8mb4_bin` pela migration.

Depois, dentro de `D:\Downloads\site-mimo\backend`:

```powershell
php -m | findstr /i pdo_mysql      # precisa aparecer "pdo_mysql"; se não, descomente extension=pdo_mysql no php.ini
php artisan config:clear
php artisan migrate:fresh --seed  # banco novo e vazio: apaga tudo do banco local
```

## 4. Rodar os testes como o CI

Os testes antigos e os de concorrência usam esse banco de verdade; os de caracterização seguem em SQLite.

```powershell
vendor\bin\phpunit                          # suíte completa
vendor\bin\phpunit --group=concurrency      # só os testes com dois processos (~30 s)
```

Os testes de concorrência apagam os dados das tabelas do banco a cada teste. Se quiser manter o banco de
desenvolvimento intacto, crie um banco só para testes e use-o ao rodar o phpunit:

```powershell
docker exec mimoo-mariadb mariadb -uroot -proot-local -e "CREATE DATABASE mimoo_test; GRANT ALL ON mimoo_test.* TO 'mimoo'@'%';"
$env:DB_DATABASE="mimoo_test"; php artisan migrate:fresh --force; vendor\bin\phpunit; Remove-Item Env:DB_DATABASE
```

## 5. Dia a dia

- Parar: `docker stop mimoo-mariadb` | Ligar: `docker start mimoo-mariadb`
- Abrir o console do banco: `docker exec -it mimoo-mariadb mariadb -umimoo -pmimoo-local mimoo`
- Apagar tudo e começar de novo: `docker rm -f mimoo-mariadb` e `docker volume rm mimoo-mariadb-dados`, depois o passo 2.
