#!/usr/bin/env bash
# M-50/M-53: atualiza a API/painel na Hostinger a partir da branch `producao` (roteiro: docs/deploy-hostinger.md).
#
# Uso (no SSH da Hostinger):
#   bash ~/site-mimo/deploy/hostinger/deploy.sh              # publica o que está em origin/producao
#   bash ~/site-mimo/deploy/hostinger/deploy.sh <commit>     # volta para um commit já publicado na producao
#   bash ~/site-mimo/deploy/hostinger/deploy.sh -y [...]     # sem a pergunta de confirmação
#
# Ordem: manutenção → código → composer2 → migrations → caches → mimoo:check-production → worker → sai da manutenção.
# O site só sai da manutenção se TODOS os passos passarem, inclusive o check. Em qualquer falha o site FICA em
# manutenção (503; o Mercado Pago reenvia o webhook), o script mostra o passo que falhou e os comandos para voltar
# atrás, e sai com código diferente de zero.
#
# Não lê nem imprime o .env.
set -Eeuo pipefail

# Arquivos que este script cria (caches com as configurações, log, manutenção) só o seu usuário lê.
umask 077

# --- Ajuste se o seu caminho for outro (MIMOO_* só existem para o teste em deploy/hostinger/tests) -----------
PHP="${MIMOO_PHP:-/opt/alt/php84/usr/bin/php}"
APP_DIR="${MIMOO_APP_DIR:-${HOME}/site-mimo}"
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

step() { CURRENT_STEP="$*"; printf '\n==> %s\n' "$*"; }
fail() { printf '\nERRO: %s\n' "$*" >&2; exit 1; }

CURRENT_STEP="conferências iniciais"
IN_MAINTENANCE=0
PREVIOUS=""

on_error() {
    local code=$?
    trap - ERR INT TERM
    [ -z "${1:-}" ] || code="$1"
    [ "$code" -ne 0 ] || code=1
    {
        printf '\nERRO no passo: %s (código %s).\n' "$CURRENT_STEP" "$code"
        if [ "$IN_MAINTENANCE" -eq 1 ]; then
            printf 'O site CONTINUA EM MANUTENÇÃO (503). Nada foi tirado do ar à força.\n\n'
            printf 'Para voltar atrás:\n'
            printf '  1. Corrija o problema e rode de novo:   bash %s/deploy/hostinger/deploy.sh\n' "$APP_DIR"
            if [ -n "$PREVIOUS" ]; then
                printf '  2. Ou volte para a versão anterior:     bash %s/deploy/hostinger/deploy.sh %s\n' "$APP_DIR" "${PREVIOUS:0:12}"
            fi
            printf '  3. Se o passo foi "Migrations", veja a seção "Voltar atrás" da fase 4 no roteiro antes de voltar.\n'
            printf '  4. Só tire da manutenção à mão depois de conferir que está tudo certo:\n'
            printf '       cd %s/backend && %s artisan mimoo:check-production && %s artisan up\n' "$APP_DIR" "$PHP" "$PHP"
        else
            printf 'O site não foi colocado em manutenção: nada mudou no ar.\n'
        fi
    } >&2
    exit "$code"
}
trap on_error ERR
trap 'on_error 130' INT TERM

COMPOSER="$(command -v composer2 || true)"
[ -n "$COMPOSER" ] || fail "composer2 não encontrado (na Hostinger, 'composer' é a versão 1)."
[ -x "$PHP" ] || fail "PHP não encontrado em $PHP."
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' || fail "PHP abaixo de 8.4.27 em $PHP."
COMPOSER_VERSION="$("$PHP" "$COMPOSER" --version 2>/dev/null || true)"
[[ "$COMPOSER_VERSION" == *"Composer version 2."* ]] || fail "composer2 não é a versão 2.x."

cd "$APP_DIR"
CURRENT_BRANCH="$(git symbolic-ref --quiet --short HEAD || true)"
[ "$CURRENT_BRANCH" = "$BRANCH" ] \
    || fail "O clone está em '${CURRENT_BRANCH:-commit solto}', não na branch $BRANCH. Rode: git -C $APP_DIR checkout $BRANCH. Nada foi feito."
DIRTY="$(git status --porcelain)"
[ -z "$DIRTY" ] || fail "Há arquivos alterados ou novos no repositório do servidor (git status abaixo). Nada foi feito.
$DIRTY"

step "Buscando a branch $BRANCH"
git fetch --quiet origin "$BRANCH"
PREVIOUS="$(git rev-parse HEAD)"
NEXT="$(git rev-parse --verify --quiet "${TARGET:-origin/$BRANCH}^{commit}" || true)"
[ -n "$NEXT" ] || fail "Commit não encontrado: ${TARGET:-origin/$BRANCH}"

if [ -z "$TARGET" ]; then
    git merge-base --is-ancestor "$PREVIOUS" "$NEXT" \
        || fail "origin/$BRANCH não é continuação da versão no ar (avanço só por fast-forward). Para voltar versão, passe o commit."
else
    git merge-base --is-ancestor "$NEXT" "origin/$BRANCH" \
        || fail "O commit $TARGET não faz parte da origin/$BRANCH: só se volta para uma versão que já foi publicada."
fi

echo "Versão no ar agora: $(git log -1 --format='%h %s' "$PREVIOUS")"
echo "Versão que vai entrar: $(git log -1 --format='%h %s' "$NEXT")"
echo "Para voltar depois: bash $APP_DIR/deploy/hostinger/deploy.sh ${PREVIOUS:0:12}"
if [ "$PREVIOUS" = "$NEXT" ]; then
    echo "Nada novo para publicar. (Os caches e o check rodam mesmo assim.)"
fi
if [ "$YES" -ne 1 ]; then
    read -r -p "Continuar? [s/N] " answer
    [ "$answer" = "s" ] || [ "$answer" = "S" ] || fail "Cancelado. Nada foi feito."
fi

cd "$APP_DIR/backend"

step "Modo manutenção (503 com Retry-After)"
"$PHP" artisan down --retry=60
IN_MAINTENANCE=1

step "Código ($( [ -n "$TARGET" ] && echo "volta para $TARGET" || echo "fast-forward de origin/$BRANCH"))"
# Código e dependências com a permissão normal (644/755): o servidor web precisa ler os estáticos de public/,
# inclusive o CSS/JS do painel que o composer publica (filament:upgrade no post-autoload-dump). Só os arquivos
# gerados nos passos seguintes (caches com a configuração) ficam com o umask 077. O "trap - ERR" no subshell evita
# a mensagem de erro em dobro.
if [ -n "$TARGET" ]; then
    (trap - ERR; umask 022 && git -C "$APP_DIR" reset --quiet --hard "$NEXT")
else
    (trap - ERR; umask 022 && git -C "$APP_DIR" merge --quiet --ff-only "origin/$BRANCH")
fi

step "Dependências (composer2, sem pacotes de desenvolvimento)"
(trap - ERR; umask 022 && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress)

step "Migrations"
"$PHP" artisan migrate --force

step "Caches"
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

step "mimoo:check-production (precisa passar para sair da manutenção)"
"$PHP" artisan mimoo:check-production

step "Worker da fila pega o código novo no próximo minuto"
"$PHP" artisan queue:restart

step "Saindo da manutenção"
"$PHP" artisan up
IN_MAINTENANCE=0
trap - ERR INT TERM

echo
echo "Deploy ok: $(git -C "$APP_DIR" log -1 --format='%h %s')"
