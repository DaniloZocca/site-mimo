#!/usr/bin/env bash
# M-50/M-53: backup diário do banco e das imagens dos produtos, com cópia CRIPTOGRAFADA fora da Hostinger
# (rclone com remoto "crypt" no Google Drive da conta da loja). Roteiro: docs/deploy-hostinger.md.
#
# Cron (Personalizado), todo dia às 03:00 de Brasília = 06:00 UTC (confira o fuso que o hPanel usa):
#   0 6 * * * bash /home/<usuário>/site-mimo/deploy/hostinger/backup.sh >> /home/<usuário>/backups/backup.log 2>&1
#
# Senha do banco: só em ~/.my.cnf (chmod 600), nunca aqui nem na linha de comando:
#   [client]
#   user=<usuário do banco>
#   password=<senha do banco>
#   host=localhost
#
# Retenção, no servidor e fora dele: 7 diários + 4 semanais (o de domingo, UTC).
#   ~/backups/diario/   e   ~/backups/semanal/        (servidor)
#   <remoto>diario/     e   <remoto>semanal/          (Google Drive, criptografado)
#
# O dump tem dados pessoais de clientes (LGPD): a cópia local fica só no servidor (chmod 600) e a de fora só
# vai pelo remoto criptografado. Cada execução escreve UMA linha no log (data, resultado, nomes e tamanhos dos
# arquivos; nada do banco). Em falha, a linha diz o passo, o código de saída é ≠ 0 e a mensagem da ferramenta
# fica em ~/backups/ultimo-erro.txt (sobrescrito a cada execução).
set -Eeuo pipefail

# --- Valores padrão. NÃO edite aqui (o deploy.sh recusa arquivo do repositório alterado no servidor):
# --- sobrescreva em ~/.mimoo-backup.conf (chmod 600), ex.:  DB_NAME="u123456789_mimoo"
DB_NAME=""                                      # obrigatório: nome do banco no hPanel (não é segredo)
APP_DIR="${HOME}/site-mimo"
BACKUP_DIR="${HOME}/backups"
RCLONE="${HOME}/bin/rclone"
REMOTE="mimoo-backup-crypt:"                    # remoto crypt criado com `rclone config`
KEEP_DAILY=7
KEEP_WEEKLY=4
HEALTHCHECK_URL=""                              # opcional: URL de ping (ex.: healthchecks.io) para avisar se parar
CONF="${HOME}/.mimoo-backup.conf"
# shellcheck source=/dev/null
[ -f "$CONF" ] && . "$CONF"
# -----------------------------------------------------------------------------------------------------------

umask 077
mkdir -p "$BACKUP_DIR/diario" "$BACKUP_DIR/semanal"
STAMP="$(date -u +%Y-%m-%dT%H%MZ)"
DB_FILE="$BACKUP_DIR/diario/db-$STAMP.sql.gz"
FILES_FILE="$BACKUP_DIR/diario/imagens-$STAMP.tar.gz"
ERR_FILE="$BACKUP_DIR/ultimo-erro.txt"
WEEKLY=0
if [ "$(date -u +%u)" = "7" ]; then WEEKLY=1; fi
STEP="início"
LOCAL_OK=0

# Mensagens das ferramentas (mariadb-dump, tar, rclone) vão para o arquivo de erro, não para o log
: > "$ERR_FILE"
exec 3>&2 2>>"$ERR_FILE"

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
size() { du -h "$1" | cut -f1; }

on_error() {
    local code=$?
    trap - ERR
    [ "$code" -ne 0 ] || code=1
    # Arquivo pela metade não fica parecendo backup bom; o backup local completo fica (falha só no envio)
    [ "$LOCAL_OK" -eq 1 ] || rm -f "$DB_FILE" "$FILES_FILE"
    log "ERRO backup passo=\"$STEP\" codigo=$code backup_local=$([ "$LOCAL_OK" -eq 1 ] && echo mantido || echo apagado) detalhes=$ERR_FILE"
    exit "$code"
}
trap on_error ERR

# Mantém os N mais novos de cada tipo (o nome tem a data, então a ordem alfabética é a cronológica)
prune_local() {
    local dir="$1" keep="$2" prefix
    for prefix in db- imagens-; do
        find "$dir" -maxdepth 1 -type f -name "${prefix}*" -print | sort -r | tail -n +"$((keep + 1))" | while IFS= read -r old; do
            rm -f -- "$old"
        done
    done
}

STEP="conferências"
DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
[ -n "$DUMP" ] || { echo "mariadb-dump/mysqldump não encontrado" >&2; false; }
[ -x "$RCLONE" ] || { echo "rclone não encontrado em $RCLONE" >&2; false; }
[ -n "$DB_NAME" ] || { echo "defina DB_NAME em $CONF" >&2; false; }

# --single-transaction: cópia consistente sem travar a loja (tabelas InnoDB)
STEP="dump do banco"
"$DUMP" --single-transaction --quick --default-character-set=utf8mb4 --no-tablespaces "$DB_NAME" | gzip -9 > "$DB_FILE"
gzip -t "$DB_FILE"

STEP="imagens"
tar -czf "$FILES_FILE" -C "$APP_DIR/backend/storage/app" public
gzip -t "$FILES_FILE"
LOCAL_OK=1

STEP="semanal local"
if [ "$WEEKLY" -eq 1 ]; then
    cp -p "$DB_FILE" "$FILES_FILE" "$BACKUP_DIR/semanal/"
fi

# Retenção no servidor antes do envio: se o envio falhar todo dia, a pasta não cresce até lotar a conta
STEP="retenção local"
prune_local "$BACKUP_DIR/diario" "$KEEP_DAILY"
prune_local "$BACKUP_DIR/semanal" "$KEEP_WEEKLY"

STEP="cópia fora do servidor"
"$RCLONE" copy --quiet "$DB_FILE" "${REMOTE}diario/"
"$RCLONE" copy --quiet "$FILES_FILE" "${REMOTE}diario/"
if [ "$WEEKLY" -eq 1 ]; then
    "$RCLONE" copy --quiet "$DB_FILE" "${REMOTE}semanal/"
    "$RCLONE" copy --quiet "$FILES_FILE" "${REMOTE}semanal/"
fi

STEP="retenção fora do servidor"
# Por idade (um backup por dia/semana)
"$RCLONE" delete --quiet --min-age "$((KEEP_DAILY + 1))d" "${REMOTE}diario/"
"$RCLONE" delete --quiet --min-age "$((KEEP_WEEKLY * 7 + 1))d" "${REMOTE}semanal/"

PING="sem"
if [ -n "$HEALTHCHECK_URL" ]; then
    if curl -fsS -m 10 --retry 3 "$HEALTHCHECK_URL" > /dev/null; then PING="ok"; else PING="falhou"; fi
fi

trap - ERR
exec 2>&3 3>&-
log "ok backup banco=$(basename "$DB_FILE") $(size "$DB_FILE") imagens=$(basename "$FILES_FILE") $(size "$FILES_FILE") semanal=$([ "$WEEKLY" -eq 1 ] && echo sim || echo nao) ping=$PING"
