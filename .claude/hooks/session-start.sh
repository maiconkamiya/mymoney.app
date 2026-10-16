#!/bin/bash
# Prepara o ambiente das sessões do Claude Code na nuvem para rodar `composer ci`.
# Só roda na nuvem: na máquina de quem desenvolve, não faz nada.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}"
export COMPOSER_ALLOW_SUPERUSER=1
# Na nuvem o usuário é root: sem isso o Composer recusa rodar `composer ci`.
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  echo 'export COMPOSER_ALLOW_SUPERUSER=1' >> "$CLAUDE_ENV_FILE"
fi

# O proxy da sessão devolve 403 para os zips de api.github.com (repositórios não
# anexados à sessão). Em vez de pedir o zip, montamos o mesmo arquivo com
# `git fetch` + `git archive` e o colocamos no cache de dist do Composer, que
# então instala sem tocar na API.
composer config -g use-github-api false

cache_dir="$(composer config -g cache-files-dir)"

prime_package() {
  local name="$1" ref="$2" url="$3" work
  # Chave do cache do Composer: <pacote>/sha1(url do dist).zip
  local cached="$cache_dir/$name/$(printf '%s' "$url" | sha1sum | cut -d' ' -f1).zip"
  [ -s "$cached" ] && return 0

  local repo="${url#https://api.github.com/repos/}"
  repo="${repo%%/zipball/*}"
  work="$(mktemp -d)"
  git -C "$work" init -q
  git -C "$work" fetch -q --depth 1 "https://github.com/$repo.git" "$ref"
  mkdir -p "$(dirname "$cached")"
  git -C "$work" archive --format=zip --prefix="${repo//\//-}-${ref:0:7}/" -o "$work/pkg.zip" FETCH_HEAD
  mv "$work/pkg.zip" "$cached"
  rm -rf "$work"
}
export -f prime_package
export cache_dir

jq -r '[.packages[], .["packages-dev"][]][]
  | select(.dist.url | startswith("https://api.github.com/repos/"))
  | [.name, .dist.reference, .dist.url] | @tsv' composer.lock \
  | xargs -P 8 -L 1 bash -c 'prime_package "$0" "$1" "$2"'

composer install --no-interaction --no-progress

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --ansi
fi

npm install --no-audit --no-fund
