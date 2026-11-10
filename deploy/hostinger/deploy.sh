#!/usr/bin/env bash
# M-50: atualiza a API/painel na Hostinger a partir da branch `producao` (roteiro: docs/deploy-hostinger.md).
#
# Uso (no SSH da Hostinger):
#   bash ~/site-mimo/deploy/hostinger/deploy.sh              # publica o que está em origin/producao
#   bash ~/site-mimo/deploy/hostinger/deploy.sh <commit>     # publica um commit específico (voltar versão)
#   bash ~/site-mimo/deploy/hostinger/deploy.sh -y [...]     # sem a pergunta de confirmação
#
# Não lê nem imprime o .env. Se um passo falhar, o site FICA em manutenção (503; o Mercado Pago reenvia o
# webhook) até você corrigir e rodar de novo, ou voltar com o commit anterior que o script mostra no início.
set -euo pipefail

# --- Ajuste se o seu caminho for outro ---------------------------------------------------------------------
PHP="/opt/alt/php84/usr/bin/php"
APP_DIR="${HOME}/site-mimo"
BRANCH="producao"
# -----------------------------------------------------------------------------------------------------------

YES=0
TARGET=""
for arg in "$@"; do
    case "$arg" in
        -y) YES=1 ;;
        -*) echo "Opção desconhecida: $arg" >&2; exit 2 ;;
        *) TARGET="$arg" ;;
    esac
done

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nERRO: %s\n' "$*" >&2; exit 1; }

COMPOSER="$(command -v composer2 || true)"
[ -n "$COMPOSER" ] || fail "composer2 não encontrado (na Hostinger, 'composer' é a versão 1)."
[ -x "$PHP" ] || fail "PHP não encontrado em $PHP."
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' || fail "PHP abaixo de 8.4.27 em $PHP."
COMPOSER_VERSION="$("$PHP" "$COMPOSER" --version 2>/dev/null || true)"
[[ "$COMPOSER_VERSION" == *"Composer version 2."* ]] || fail "composer2 não é a versão 2.x."

cd "$APP_DIR"
[ -z "$(git status --porcelain --untracked-files=no)" ] || fail "Há arquivos do repositório alterados no servidor (git status). Nada foi feito."

step "Buscando a branch $BRANCH"
git fetch --quiet origin "$BRANCH"
PREVIOUS="$(git rev-parse HEAD)"
NEXT="$(git rev-parse "${TARGET:-origin/$BRANCH}^{commit}")" || fail "Commit não encontrado: ${TARGET}"

echo "Versão no ar agora: $(git log -1 --format='%h %s' "$PREVIOUS")"
echo "Versão que vai entrar: $(git log -1 --format='%h %s' "$NEXT")"
echo "Para voltar depois: bash $APP_DIR/deploy/hostinger/deploy.sh ${PREVIOUS:0:12}"
if [ "$PREVIOUS" = "$NEXT" ]; then
    echo "Nada novo para publicar. (Os caches e o check rodam mesmo assim.)"
fi
if [ -z "$TARGET" ] && ! git merge-base --is-ancestor "$PREVIOUS" "$NEXT"; then
    fail "origin/$BRANCH não é continuação da versão no ar (avanço só por fast-forward). Para voltar versão, passe o commit."
fi
if [ "$YES" -ne 1 ]; then
    read -r -p "Continuar? [s/N] " answer
    [ "$answer" = "s" ] || [ "$answer" = "S" ] || fail "Cancelado. Nada foi feito."
fi

cd "$APP_DIR/backend"

step "Modo manutenção (503 com Retry-After)"
"$PHP" artisan down --retry=60

trap 'printf "\nERRO no passo acima. O site continua em manutenção.\nCorrija e rode de novo, ou volte: bash %s/deploy/hostinger/deploy.sh %s\n(Para tirar da manutenção à mão: %s artisan up)\n" "$APP_DIR" "${PREVIOUS:0:12}" "$PHP" >&2' ERR

step "Código ($( [ -n "$TARGET" ] && echo "commit $TARGET" || echo "fast-forward de origin/$BRANCH"))"
git -C "$APP_DIR" checkout --quiet --detach "$NEXT"

step "Dependências (composer2, sem pacotes de desenvolvimento)"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

step "Migrations"
"$PHP" artisan migrate --force

step "Caches"
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

step "Worker da fila pega o código novo no próximo minuto"
"$PHP" artisan queue:restart

step "Saindo da manutenção"
"$PHP" artisan up
trap - ERR

step "mimoo:check-production"
if "$PHP" artisan mimoo:check-production; then
    echo
    echo "Deploy ok: $(git -C "$APP_DIR" log -1 --format='%h %s')"
else
    echo
    echo "ATENÇÃO: o site está no ar, mas o mimoo:check-production reprovou (veja acima)." >&2
    echo "No ensaio (APP_ENV=staging) só o APP_ENV e as credenciais de teste podem aparecer aqui." >&2
    exit 1
fi
