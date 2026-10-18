#!/usr/bin/env bash
# Deploy do MyMoney.App na produção (Ubuntu no WSL, serviços systemd de usuário).
#
# Uso, dentro do clone de produção:
#   deploy/deploy.sh [--dry-run] [REF]
#   composer deploy -- [--dry-run] [REF]
#
# REF é a branch ou a tag a publicar (padrão: main). Este script NÃO substitui o
# `composer ci`: a CI roda na nuvem antes do PR e aqui só se publica o que já foi mesclado.
#
# Ordem importa: os caches do Laravel são limpos DEPOIS do `git pull` e ANTES do
# `npm run build`. O plugin Wayfinder gera as rotas TypeScript a partir das rotas do
# Laravel; com o cache de rotas antigo, uma rota nova não existe e o build falha com
# `"update" is not exported by resources/js/routes/...`. Só depois do build se refaz o cache.

set -euo pipefail

REF="main"
DRY_RUN=0
SERVICOS_REINICIAR=(mymoney-reverb mymoney-binance mymoney-micro mymoney-forecast)
SERVICOS_ESTADO=(mymoney-web mymoney-queue mymoney-schedule mymoney-reverb mymoney-binance mymoney-micro mymoney-forecast)

uso() {
    cat <<'USO'
Uso: deploy/deploy.sh [--dry-run] [REF]

  REF         branch ou tag a publicar (padrão: main)
  --dry-run   só lista o que seria feito, sem alterar nada
  -h, --help  mostra esta ajuda
USO
}

erro() {
    printf '\033[31m✗ %s\033[0m\n' "$*" >&2
    exit 1
}

passo() {
    printf '\n\033[1m▶ %s\033[0m\n' "$*"
}

info() {
    printf '  %s\n' "$*"
}

# Executa o comando, ou só o mostra no --dry-run.
rodar() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '  [dry-run] %s\n' "$*"
    else
        printf '  $ %s\n' "$*"
        "$@"
    fi
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        -h | --help)
            uso
            exit 0
            ;;
        -*)
            uso >&2
            erro "Opção desconhecida: $1"
            ;;
        *) REF="$1" ;;
    esac
    shift
done

trap 'erro "O deploy parou no comando acima (linha $LINENO). Corrija e rode de novo: os passos são seguros de repetir."' ERR

passo "Conferindo o ambiente"

[ "$(uname -s)" = "Linux" ] || erro "Este script só roda em Linux (a produção é o Ubuntu no WSL)."

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"
[ -f artisan ] && [ -f package.json ] || erro "Não achei o app em $RAIZ (faltam artisan e package.json)."
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || erro "$RAIZ não é um clone git."
[ -f .env ] || erro "Falta o .env em $RAIZ. Crie a partir do guia de produção antes do deploy."

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no >&2
    erro "Há mudanças locais não commitadas (listadas acima). Guarde ou descarte antes do deploy."
fi

for ferramenta in git php composer node npm; do
    command -v "$ferramenta" >/dev/null 2>&1 || erro "Não encontrei '$ferramenta' no PATH."
done

[ "$DRY_RUN" -eq 1 ] && info "Modo --dry-run: nada será alterado (só o git fetch roda)."

passo "Buscando o código ($REF)"

ANTES="$(git rev-parse HEAD)"
git fetch --quiet --tags --prune origin

if git rev-parse --verify --quiet "refs/remotes/origin/$REF" >/dev/null; then
    TIPO="branch"
    ALVO="$(git rev-parse "refs/remotes/origin/$REF")"
elif git rev-parse --verify --quiet "refs/tags/$REF^{commit}" >/dev/null; then
    TIPO="tag"
    ALVO="$(git rev-parse "refs/tags/$REF^{commit}")"
else
    erro "'$REF' não existe como branch nem como tag no origin."
fi

info "Commit atual: $(git rev-parse --short "$ANTES")"
info "Commit alvo:  $(git rev-parse --short "$ALVO") ($TIPO $REF)"

if [ "$TIPO" = "branch" ]; then
    ATUAL="$(git rev-parse --abbrev-ref HEAD)"
    if [ "$ATUAL" != "$REF" ]; then
        rodar git checkout "$REF"
    fi
    rodar git pull --ff-only origin "$REF"
else
    rodar git checkout --detach "refs/tags/$REF"
fi

if [ "$ANTES" = "$ALVO" ]; then
    info "Já está no commit alvo; o deploy segue para refazer build e caches."
fi

ARQUIVOS_MUDADOS="$(git diff --name-only "$ANTES" "$ALVO")"

mudou() {
    printf '%s\n' "$ARQUIVOS_MUDADOS" | grep -qxF "$1"
}

passo "Dependências"

if mudou composer.lock || [ ! -d vendor ]; then
    rodar composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
else
    info "composer.lock não mudou: composer install pulado."
fi

if mudou package-lock.json || [ ! -d node_modules ]; then
    rodar npm ci
else
    info "package-lock.json não mudou: npm ci pulado."
fi

passo "Caches e build"

# Veja o comentário do topo: limpar antes do build evita rotas novas ausentes no Wayfinder.
rodar php artisan optimize:clear
rodar npm run build

passo "Banco de dados e caches de produção"

rodar php artisan migrate --force
rodar php artisan optimize
rodar php artisan queue:restart

passo "Serviços"

unidade_existe() {
    systemctl --user list-unit-files --no-legend "$1.service" 2>/dev/null | grep -q .
}

if ! command -v systemctl >/dev/null 2>&1; then
    info "systemctl não encontrado: serviços não reiniciados."
else
    for servico in "${SERVICOS_REINICIAR[@]}"; do
        if unidade_existe "$servico"; then
            rodar systemctl --user restart "$servico"
        else
            info "$servico não está instalado: pulado."
        fi
    done
fi

passo "Resumo"

VERSAO="$(node -p "require('./package.json').version")"
info "Versão (package.json): $VERSAO"
info "Commit: $(git rev-parse --short HEAD)"

if command -v systemctl >/dev/null 2>&1; then
    for servico in "${SERVICOS_ESTADO[@]}"; do
        if unidade_existe "$servico"; then
            estado="$(systemctl --user is-active "$servico" 2>/dev/null || true)"
            info "$servico: ${estado:-desconhecido}"
        fi
    done
fi

if [ "$DRY_RUN" -eq 1 ]; then
    printf '\n\033[33m--dry-run: nada foi alterado.\033[0m\n'
else
    printf '\n\033[32m✓ Deploy concluído.\033[0m\n'
fi
