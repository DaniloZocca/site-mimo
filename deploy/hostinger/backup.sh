#!/usr/bin/env bash
# M-50: backup diário do banco e das imagens dos produtos, com cópia CRIPTOGRAFADA fora da Hostinger
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
# O dump tem dados pessoais de clientes (LGPD): a cópia local fica só no servidor (chmod 600) e a de fora só
# vai pelo remoto criptografado. O log grava só data, nome e tamanho dos arquivos.
set -euo pipefail

# --- Valores padrão. NÃO edite aqui (o deploy.sh recusa arquivo do repositório alterado no servidor):
# --- sobrescreva em ~/.mimoo-backup.conf (chmod 600), ex.:  DB_NAME="u123456789_mimoo"
DB_NAME=""                                      # obrigatório: nome do banco no hPanel (não é segredo)
APP_DIR="${HOME}/site-mimo"
BACKUP_DIR="${HOME}/backups"
RCLONE="${HOME}/bin/rclone"
REMOTE="mimoo-backup-crypt:"                    # remoto crypt criado com `rclone config`
KEEP_LOCAL_DAYS=7
HEALTHCHECK_URL=""                              # opcional: URL de ping (ex.: healthchecks.io) para avisar se parar
CONF="${HOME}/.mimoo-backup.conf"
# shellcheck source=/dev/null
[ -f "$CONF" ] && . "$CONF"
# -----------------------------------------------------------------------------------------------------------

umask 077
mkdir -p "$BACKUP_DIR"
STAMP="$(date -u +%Y-%m-%dT%H%MZ)"
DB_FILE="$BACKUP_DIR/db-$STAMP.sql.gz"
FILES_FILE="$BACKUP_DIR/imagens-$STAMP.tar.gz"
log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
[ -n "$DUMP" ] || { log "ERRO: mariadb-dump/mysqldump não encontrado."; exit 1; }
[ -x "$RCLONE" ] || { log "ERRO: rclone não encontrado em $RCLONE."; exit 1; }

[ -n "$DB_NAME" ] || { log "ERRO: defina DB_NAME em $CONF."; exit 1; }

log "início"

# Arquivo pela metade (dump interrompido) não fica parecendo backup bom
trap 'rm -f "$DB_FILE" "$FILES_FILE"; log "ERRO: backup interrompido; nada foi enviado."' ERR

# --single-transaction: cópia consistente sem travar a loja (tabelas InnoDB)
"$DUMP" --single-transaction --quick --default-character-set=utf8mb4 --no-tablespaces "$DB_NAME" | gzip -9 > "$DB_FILE"
gzip -t "$DB_FILE"
log "banco ok: $(basename "$DB_FILE") $(du -h "$DB_FILE" | cut -f1)"

tar -czf "$FILES_FILE" -C "$APP_DIR/backend/storage/app" public
gzip -t "$FILES_FILE"
log "imagens ok: $(basename "$FILES_FILE") $(du -h "$FILES_FILE" | cut -f1)"
trap 'log "ERRO na cópia para fora do servidor; os arquivos locais ficaram em $BACKUP_DIR."' ERR

"$RCLONE" copy --quiet "$DB_FILE" "${REMOTE}diario/"
"$RCLONE" copy --quiet "$FILES_FILE" "${REMOTE}diario/"
if [ "$(date -u +%u)" = "7" ]; then
    "$RCLONE" copy --quiet "$DB_FILE" "${REMOTE}semanal/"
    "$RCLONE" copy --quiet "$FILES_FILE" "${REMOTE}semanal/"
fi
log "cópia fora do servidor ok"

# Retenção: 7 diários e 4 semanais fora do servidor; 7 dias aqui
"$RCLONE" delete --quiet --min-age 8d "${REMOTE}diario/"
"$RCLONE" delete --quiet --min-age 29d "${REMOTE}semanal/"
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'imagens-*.tar.gz' \) -mtime +"$KEEP_LOCAL_DAYS" -delete

if [ -n "$HEALTHCHECK_URL" ]; then
    curl -fsS -m 10 --retry 3 "$HEALTHCHECK_URL" > /dev/null || log "aviso: ping de monitoramento falhou"
fi

log "fim"
