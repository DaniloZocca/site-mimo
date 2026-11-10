#!/usr/bin/env bash
# M-53: teste do deploy.sh sem servidor. Um repositório Git de mentira faz o papel do clone na Hostinger e um
# "php" falso registra cada comando artisan/composer em vez de rodar. Prova, entre outras coisas, que com o
# mimoo:check-production reprovando o "artisan up" NÃO roda e o site fica em manutenção.
#
# Uso: bash deploy/hostinger/tests/deploy_test.sh   (roda no CI, job "scripts de deploy")
# "A && ok || not_ok" é intencional: ok() sempre devolve 0, então not_ok só roda quando A falha.
# shellcheck disable=SC2015
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/deploy.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export GIT_AUTHOR_NAME=teste GIT_AUTHOR_EMAIL=teste@exemplo.test
export GIT_COMMITTER_NAME=teste GIT_COMMITTER_EMAIL=teste@exemplo.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

PASSED=0
FAILED=0
ok() { PASSED=$((PASSED + 1)); printf 'ok   %s\n' "$1"; }
not_ok() { FAILED=$((FAILED + 1)); printf 'FAIL %s\n' "$1"; [ -z "${2:-}" ] || printf '     %s\n' "$2"; }

# --- php e composer2 falsos -------------------------------------------------------------------------------
mkdir -p "$WORK/bin"
cat > "$WORK/bin/php" <<'PHP'
#!/usr/bin/env bash
# Registra o comando e falha de propósito quando FAIL_ON for o subcomando (ex.: mimoo:check-production)
if [ "$1" = "-r" ]; then exit 0; fi
if [ "$(basename "$1")" = "composer2" ]; then
    if [ "$2" = "--version" ]; then echo "Composer version 2.8.12 2025-09-19 13:41:59"; exit 0; fi
    echo "composer $2" >> "$CALLS"
    mkdir -p backend-public-filament && echo "/* asset */" > backend-public-filament/app.css
    [ "${FAIL_ON:-}" = "composer" ] && exit 1
    exit 0
fi
if [ "$1" = "artisan" ]; then
    echo "artisan $2" >> "$CALLS"
    if [ "$2" = "config:cache" ]; then echo "<?php return [];" > bootstrap-cache-config.php; fi
    [ "${FAIL_ON:-}" = "$2" ] && { echo "falha simulada em $2" >&2; exit 1; }
    exit 0
fi
exit 0
PHP
printf '#!/usr/bin/env bash\nexit 0\n' > "$WORK/bin/composer2"
chmod 755 "$WORK/bin/php" "$WORK/bin/composer2"

# --- origin (GitHub) e clone do servidor, na branch producao ----------------------------------------------
new_repo() {
    rm -rf "${WORK:?}/origin.git" "${WORK:?}/dev" "${WORK:?}/app"
    git init --quiet --bare "$WORK/origin.git"
    git clone --quiet "$WORK/origin.git" "$WORK/dev" 2>/dev/null
    git -C "$WORK/dev" checkout --quiet -b producao
    mkdir -p "$WORK/dev/backend/public"
    echo "v1" > "$WORK/dev/backend/public/app.css"
    printf 'bootstrap-cache-config.php\nbackend-public-filament/\n' > "$WORK/dev/backend/.gitignore"
    git -C "$WORK/dev" add -A && git -C "$WORK/dev" commit --quiet -m "v1"
    git -C "$WORK/dev" push --quiet origin producao
    git clone --quiet -b producao "$WORK/origin.git" "$WORK/app"
    V1="$(git -C "$WORK/app" rev-parse HEAD)"
    echo "v2" > "$WORK/dev/backend/public/app.css"
    git -C "$WORK/dev" commit --quiet -am "v2"
    git -C "$WORK/dev" push --quiet origin producao
    V2="$(git -C "$WORK/dev" rev-parse HEAD)"
}

# Roda o deploy; guarda código de saída, saída e lista de comandos chamados
run_deploy() {
    CALLS="$WORK/calls.txt"
    : > "$CALLS"
    set +e
    OUTPUT="$(CALLS="$CALLS" FAIL_ON="${FAIL_ON:-}" PATH="$WORK/bin:$PATH" MIMOO_PHP="$WORK/bin/php" \
        MIMOO_APP_DIR="$WORK/app" bash "$SCRIPT" -y "$@" 2>&1)"
    CODE=$?
    set -e
}

called() { grep -qx "$1" "$CALLS"; }
line_of() { grep -nx "$1" "$CALLS" | head -1 | cut -d: -f1; }

# --- 1. check-production reprova: site continua em manutenção ------------------------------------------------
new_repo
FAIL_ON="mimoo:check-production" run_deploy
[ "$CODE" -ne 0 ] && ok "check reprovado: sai com código ≠ 0 ($CODE)" || not_ok "check reprovado: sai com código ≠ 0" "saiu com 0"
called "artisan down" && ok "check reprovado: entrou em manutenção" || not_ok "check reprovado: entrou em manutenção"
called "artisan mimoo:check-production" && ok "check reprovado: o check rodou" || not_ok "check reprovado: o check rodou"
! called "artisan up" && ok "check reprovado: 'artisan up' NÃO rodou" || not_ok "check reprovado: 'artisan up' NÃO rodou" "$(cat "$CALLS")"
! called "artisan queue:restart" && ok "check reprovado: worker não reiniciado" || not_ok "check reprovado: worker não reiniciado"
[[ "$OUTPUT" == *"ERRO no passo: mimoo:check-production"* ]] && ok "check reprovado: mostra o passo que falhou" || not_ok "check reprovado: mostra o passo que falhou" "$OUTPUT"
[[ "$OUTPUT" == *"CONTINUA EM MANUTENÇÃO"* ]] && ok "check reprovado: avisa que segue em manutenção" || not_ok "check reprovado: avisa que segue em manutenção"
[[ "$OUTPUT" == *"deploy.sh ${V1:0:12}"* ]] && ok "check reprovado: mostra o comando de voltar para a versão anterior" || not_ok "check reprovado: mostra o comando de voltar" "$OUTPUT"

# --- 2. falha no composer e nas migrations: nada depois roda -----------------------------------------------
new_repo
FAIL_ON="composer" run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan up" && ! called "artisan migrate" && [[ "$OUTPUT" == *"ERRO no passo: Dependências"* ]]; } \
    && ok "composer falha: sem migrate, sem up, passo mostrado" || not_ok "composer falha: sem migrate, sem up" "$OUTPUT"

new_repo
FAIL_ON="migrate" run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan up" && ! called "artisan config:cache" && [[ "$OUTPUT" == *"ERRO no passo: Migrations"* ]]; } \
    && ok "migrate falha: sem caches, sem up, passo mostrado" || not_ok "migrate falha: sem caches, sem up" "$OUTPUT"

new_repo
FAIL_ON="config:cache" run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan up" && ! called "artisan mimoo:check-production"; } \
    && ok "cache falha: sem check, sem up" || not_ok "cache falha: sem check, sem up" "$OUTPUT"

# --- 3. tudo passa: check antes do up, up por último, código atualizado ----------------------------------
new_repo
run_deploy
{ [ "$CODE" -eq 0 ] && [ "$(tail -1 "$CALLS")" = "artisan up" ]; } \
    && ok "sucesso: sai com 0 e 'artisan up' é o último comando" || not_ok "sucesso: up por último" "$OUTPUT"
[ "$(line_of "artisan mimoo:check-production")" -lt "$(line_of "artisan up")" ] \
    && ok "sucesso: check roda antes do up" || not_ok "sucesso: check antes do up" "$(cat "$CALLS")"
[ "$(git -C "$WORK/app" rev-parse HEAD)" = "$V2" ] && ok "sucesso: código avançou para origin/producao" || not_ok "sucesso: código avançou"
[ "$(git -C "$WORK/app" symbolic-ref --short HEAD)" = "producao" ] && ok "sucesso: clone segue na branch producao" || not_ok "sucesso: segue na producao"
[ "$(stat -c %a "$WORK/app/backend/bootstrap-cache-config.php")" = "600" ] \
    && ok "umask 077: cache gerado pelo deploy fica 600" || not_ok "umask 077: cache 600" "$(stat -c %a "$WORK/app/backend/bootstrap-cache-config.php")"
[ "$(stat -c %a "$WORK/app/backend/public/app.css")" = "644" ] \
    && ok "arquivo do repositório atualizado fica 644 (servidor web lê os estáticos)" || not_ok "estático 644" "$(stat -c %a "$WORK/app/backend/public/app.css")"
[ "$(stat -c %a "$WORK/app/backend/backend-public-filament/app.css")" = "644" ] \
    && ok "asset publicado pelo composer fica 644 (CSS/JS do painel)" || not_ok "asset do composer 644" "$(stat -c %a "$WORK/app/backend/backend-public-filament/app.css")"
[ "$(grep -c "ERRO no passo" <<< "$(FAIL_ON="mimoo:check-production" run_deploy; echo "$OUTPUT")")" = "1" ] \
    && ok "erro aparece uma vez só" || not_ok "erro aparece uma vez só"

# --- 4. voltar versão ---------------------------------------------------------------------------------------
run_deploy "$V1"
{ [ "$CODE" -eq 0 ] && [ "$(git -C "$WORK/app" rev-parse HEAD)" = "$V1" ] && [ "$(git -C "$WORK/app" symbolic-ref --short HEAD)" = "producao" ]; } \
    && ok "voltar: commit anterior da producao, ainda na branch" || not_ok "voltar: commit anterior" "$OUTPUT"

git -C "$WORK/dev" checkout --quiet -b outra
echo "x" > "$WORK/dev/backend/public/x.css"
git -C "$WORK/dev" add -A && git -C "$WORK/dev" commit --quiet -m "fora da producao"
git -C "$WORK/dev" push --quiet origin outra
FORA="$(git -C "$WORK/dev" rev-parse HEAD)"
git -C "$WORK/app" fetch --quiet origin outra
run_deploy "$FORA"
{ [ "$CODE" -ne 0 ] && ! called "artisan down"; } \
    && ok "voltar: recusa commit que não está na producao, sem manutenção" || not_ok "voltar: recusa commit fora da producao" "$OUTPUT"

# --- 5. recusas antes de qualquer mudança ----------------------------------------------------------------------
new_repo
echo "teste" > "$WORK/app/backend/public/ip-check.php"
run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan down" && [[ "$OUTPUT" == *"ip-check.php"* ]]; } \
    && ok "árvore suja (arquivo novo): recusa sem entrar em manutenção" || not_ok "árvore suja: recusa" "$OUTPUT"
rm "$WORK/app/backend/public/ip-check.php"

echo "editado" >> "$WORK/app/backend/public/app.css"
run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan down"; } \
    && ok "árvore suja (arquivo alterado): recusa sem entrar em manutenção" || not_ok "arquivo alterado: recusa" "$OUTPUT"
git -C "$WORK/app" checkout --quiet -- .

git -C "$WORK/app" checkout --quiet -b main
run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan down" && [[ "$OUTPUT" == *"não na branch producao"* ]]; } \
    && ok "fora da branch producao: recusa sem entrar em manutenção" || not_ok "fora da producao: recusa" "$OUTPUT"

git -C "$WORK/app" checkout --quiet --detach producao
run_deploy
{ [ "$CODE" -ne 0 ] && ! called "artisan down"; } \
    && ok "commit solto (detached): recusa sem entrar em manutenção" || not_ok "detached: recusa" "$OUTPUT"

printf '\n%s ok, %s falhas\n' "$PASSED" "$FAILED"
[ "$FAILED" -eq 0 ]
