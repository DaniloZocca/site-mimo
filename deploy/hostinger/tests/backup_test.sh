#!/usr/bin/env bash
# M-53: teste do backup.sh sem servidor: mariadb-dump, rclone e o dia da semana são falsos. Prova a retenção
# 7 diários + 4 semanais no servidor, o log de uma linha e a falha com código ≠ 0.
#
# Uso: bash deploy/hostinger/tests/backup_test.sh   (roda no CI, job "scripts de deploy")
# "A && ok || not_ok" é intencional: ok() sempre devolve 0, então not_ok só roda quando A falha.
# shellcheck disable=SC2015
set -euo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/backup.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASSED=0
FAILED=0
ok() { PASSED=$((PASSED + 1)); printf 'ok   %s\n' "$1"; }
not_ok() { FAILED=$((FAILED + 1)); printf 'FAIL %s\n' "$1"; [ -z "${2:-}" ] || printf '     %s\n' "$2"; }

REAL_DATE="$(command -v date)"
mkdir -p "$WORK/fakebin"
cat > "$WORK/fakebin/mariadb-dump" <<'SH'
#!/usr/bin/env bash
echo "-- dump de teste"
SH
# date +%u devolve FAKE_DOW (1 = segunda, 7 = domingo); o resto é o date de verdade
cat > "$WORK/fakebin/date" <<SH
#!/usr/bin/env bash
if [ "\$*" = "-u +%u" ]; then echo "\$FAKE_DOW"; exit 0; fi
exec "$REAL_DATE" "\$@"
SH
chmod 755 "$WORK/fakebin/mariadb-dump" "$WORK/fakebin/date"

setup_home() {
    rm -rf "${WORK:?}/home"
    H="$WORK/home"
    mkdir -p "$H/bin" "$H/site-mimo/backend/storage/app/public/products" "$H/backups/diario" "$H/backups/semanal" "$H/remote"
    echo "foto" > "$H/site-mimo/backend/storage/app/public/products/a.png"
    echo 'DB_NAME="banco_teste"' > "$H/.mimoo-backup.conf"
    cat > "$H/bin/rclone" <<'SH'
#!/usr/bin/env bash
echo "rclone $*" >> "$HOME/rclone.txt"
[ "${FAIL_RCLONE:-0}" = "1" ] && { echo "rclone: falha simulada" >&2; exit 1; }
exit 0
SH
    chmod 755 "$H/bin/rclone"
    # Backups antigos: 9 diários e 6 semanais de cada tipo (datas de 2026-01-01 em diante)
    local i
    for i in 01 02 03 04 05 06 07 08 09; do
        touch "$H/backups/diario/db-2026-01-${i}T0600Z.sql.gz" "$H/backups/diario/imagens-2026-01-${i}T0600Z.tar.gz"
    done
    for i in 01 02 03 04 05 06; do
        touch "$H/backups/semanal/db-2025-12-${i}T0600Z.sql.gz" "$H/backups/semanal/imagens-2025-12-${i}T0600Z.tar.gz"
    done
    touch -d '2026-01-10' "$H/backups/diario/"* "$H/backups/semanal/"*
    touch "$H/marca"   # arquivos mais novos que isto foram criados pelo backup
}

run_backup() {
    set +e
    OUTPUT="$(HOME="$H" PATH="$WORK/fakebin:$PATH" FAKE_DOW="$1" FAIL_RCLONE="${FAIL_RCLONE:-0}" bash "$SCRIPT" 2>&1)"
    CODE=$?
    set -e
}

count() { find "$1" -maxdepth 1 -type f -name "$2" | wc -l | tr -d ' '; }

# --- 1. dia comum: 7 diários de cada tipo, semanais intocados (só poda) -------------------------------------
setup_home
run_backup 3
[ "$CODE" -eq 0 ] && ok "dia comum: sai com 0" || not_ok "dia comum: sai com 0" "$OUTPUT"
[ "$(printf '%s\n' "$OUTPUT" | wc -l | tr -d ' ')" = "1" ] && ok "dia comum: log de uma linha" || not_ok "dia comum: log de uma linha" "$OUTPUT"
[[ "$OUTPUT" == *" ok backup banco=db-"*"semanal=nao"* ]] && ok "dia comum: linha diz ok, arquivo e semanal=nao" || not_ok "dia comum: conteúdo da linha" "$OUTPUT"
[ "$(count "$H/backups/diario" 'db-*')" = "7" ] && [ "$(count "$H/backups/diario" 'imagens-*')" = "7" ] \
    && ok "dia comum: 7 diários de banco e 7 de imagens no servidor" || not_ok "dia comum: 7 diários" "$(ls "$H/backups/diario")"
[ ! -e "$H/backups/diario/db-2026-01-01T0600Z.sql.gz" ] && [ ! -e "$H/backups/diario/db-2026-01-03T0600Z.sql.gz" ] && [ -e "$H/backups/diario/db-2026-01-04T0600Z.sql.gz" ] \
    && ok "dia comum: apagou os 3 diários mais antigos" || not_ok "dia comum: apagou os mais antigos" "$(ls "$H/backups/diario")"
[ "$(count "$H/backups/semanal" 'db-*')" = "4" ] && [ "$(count "$H/backups/semanal" 'imagens-*')" = "4" ] \
    && ok "dia comum: semanais podados para 4 de cada tipo" || not_ok "dia comum: 4 semanais" "$(ls "$H/backups/semanal")"
! grep -q "semanal/\$" <(grep "copy" "$H/rclone.txt") && ok "dia comum: nada enviado para semanal/ fora do servidor" || not_ok "dia comum: sem envio semanal" "$(cat "$H/rclone.txt")"
grep -q "delete --quiet --min-age 8d mimoo-backup-crypt:diario/" "$H/rclone.txt" && grep -q "delete --quiet --min-age 29d mimoo-backup-crypt:semanal/" "$H/rclone.txt" \
    && ok "dia comum: retenção fora do servidor (8d diário, 29d semanal)" || not_ok "dia comum: retenção remota" "$(cat "$H/rclone.txt")"
NEW_DB="$(find "$H/backups/diario" -newer "$H/marca" -name 'db-*' | head -1)"
[ -n "$NEW_DB" ] && [ "$(stat -c %a "$NEW_DB")" = "600" ] && ok "dia comum: dump novo com permissão 600" || not_ok "dia comum: dump 600" "$NEW_DB"

# --- 2. domingo: cópia semanal no servidor e fora dele, 4 semanais ------------------------------------------
setup_home
run_backup 7
[ "$CODE" -eq 0 ] && [[ "$OUTPUT" == *"semanal=sim"* ]] && ok "domingo: sai com 0 e semanal=sim" || not_ok "domingo: semanal=sim" "$OUTPUT"
[ "$(count "$H/backups/semanal" 'db-*')" = "4" ] && [ "$(count "$H/backups/semanal" 'imagens-*')" = "4" ] \
    && ok "domingo: 4 semanais de cada tipo (o novo entra, os antigos saem)" || not_ok "domingo: 4 semanais" "$(ls "$H/backups/semanal")"
[ -n "$(find "$H/backups/semanal" -newer "$H/marca" -name 'db-*')" ] && ok "domingo: o semanal mais novo é o de hoje" || not_ok "domingo: semanal de hoje" "$(ls "$H/backups/semanal")"
[ "$(grep -c "copy .* mimoo-backup-crypt:semanal/" "$H/rclone.txt")" = "2" ] && ok "domingo: banco e imagens enviados para semanal/" || not_ok "domingo: envio semanal" "$(cat "$H/rclone.txt")"

# --- 3. falha no envio: código ≠ 0, uma linha com o passo, backup local mantido ------------------------------
setup_home
FAIL_RCLONE=1 run_backup 3
[ "$CODE" -ne 0 ] && ok "rclone falha: sai com código ≠ 0 ($CODE)" || not_ok "rclone falha: código ≠ 0" "$OUTPUT"
[ "$(printf '%s\n' "$OUTPUT" | wc -l | tr -d ' ')" = "1" ] && [[ "$OUTPUT" == *'ERRO backup passo="cópia fora do servidor"'*"backup_local=mantido"* ]] \
    && ok "rclone falha: uma linha com o passo e backup_local=mantido" || not_ok "rclone falha: linha do log" "$OUTPUT"
grep -q "falha simulada" "$H/backups/ultimo-erro.txt" && ok "rclone falha: mensagem da ferramenta no ultimo-erro.txt" || not_ok "rclone falha: ultimo-erro.txt"
[ -n "$(find "$H/backups/diario" -newer "$H/marca" -name 'db-*')" ] && ok "rclone falha: dump de hoje continua no servidor" || not_ok "rclone falha: dump mantido"
[ "$(count "$H/backups/diario" 'db-*')" = "7" ] && ok "rclone falha: retenção local roda mesmo assim (7 diários)" || not_ok "rclone falha: retenção local" "$(ls "$H/backups/diario")"

# --- 4. sem DB_NAME: falha antes de qualquer arquivo ------------------------------------------------------------
setup_home
echo 'DB_NAME=""' > "$H/.mimoo-backup.conf"
run_backup 3
{ [ "$CODE" -ne 0 ] && [[ "$OUTPUT" == *'ERRO backup passo="conferências"'* ]] && [ "$(count "$H/backups/diario" 'db-*')" = "9" ]; } \
    && ok "sem DB_NAME: falha nas conferências sem criar nem apagar nada" || not_ok "sem DB_NAME" "$OUTPUT"

printf '\n%s ok, %s falhas\n' "$PASSED" "$FAILED"
[ "$FAILED" -eq 0 ]
