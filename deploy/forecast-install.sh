#!/usr/bin/env bash
# Instala o serviço de previsão na produção (Ubuntu no WSL): venv Python, dependências e a
# unidade systemd de usuário `mymoney-forecast`. Seguro de repetir.
#
# Uso, dentro do clone de produção:
#   deploy/forecast-install.sh [--dry-run]
#
# Não liga FORECAST_ENABLED nem treina o modelo: veja a seção "Serviço de previsão" do README.

set -euo pipefail

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

erro() { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
passo() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }
rodar() {
    if [ "$DRY_RUN" -eq 1 ]; then printf '  [dry-run] %s\n' "$*"; else printf '  $ %s\n' "$*"; "$@"; fi
}

[ "$(uname -s)" = "Linux" ] || erro "Este script só roda em Linux (a produção é o Ubuntu no WSL)."

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICO="$RAIZ/services/forecast"
UNIDADE="$RAIZ/deploy/systemd/mymoney-forecast.service"
DESTINO="$HOME/.config/systemd/user"

[ -f "$SERVICO/requirements.txt" ] || erro "Não achei $SERVICO/requirements.txt."
command -v python3 >/dev/null 2>&1 || erro "python3 não encontrado."
python3 -c 'import venv, ensurepip' 2>/dev/null || erro "Falta o módulo venv: sudo apt install python3-venv"

passo "Ambiente Python (services/forecast/.venv)"
[ -d "$SERVICO/.venv" ] || rodar python3 -m venv "$SERVICO/.venv"
rodar "$SERVICO/.venv/bin/pip" install --quiet --upgrade pip
rodar "$SERVICO/.venv/bin/pip" install --quiet -r "$SERVICO/requirements.txt"

passo "Unidade systemd de usuário"
rodar mkdir -p "$DESTINO"
rodar install -m 0644 "$UNIDADE" "$DESTINO/mymoney-forecast.service"
rodar chmod +x "$RAIZ/deploy/forecast-serve.sh"
rodar systemctl --user daemon-reload
rodar systemctl --user enable --now mymoney-forecast

passo "Próximos passos (no .env de produção, valores só no servidor)"
cat <<PROX
  FORECAST_ENABLED=true
  FORECAST_URL=http://127.0.0.1:8001
  FORECAST_TOKEN=<gere com: openssl rand -hex 32>
  FORECAST_PYTHON=$SERVICO/.venv/bin/python

  Depois: php artisan config:clear && php artisan optimize && php artisan queue:restart
  Histórico e treino: php artisan market:backfill-binance --days=30 && php artisan forecast:train
  Conferir: curl -s http://127.0.0.1:8001/health
PROX
