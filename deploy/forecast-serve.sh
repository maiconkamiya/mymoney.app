#!/usr/bin/env bash
# Sobe a API de previsão (uvicorn) com o Python do venv de services/forecast.
# Usada pela unidade mymoney-forecast. Lê só FORECAST_TOKEN e FORECAST_PORT do .env
# (o resto do .env, com segredos de corretora, não é exposto ao processo Python).
#
# O modelo é lido de storage/app/private/forecast/models, onde `php artisan forecast:train`
# grava (disco "local" do Laravel). A API recarrega sozinha quando um novo treino grava.

set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICO="$RAIZ/services/forecast"
PYTHON="$SERVICO/.venv/bin/python"

[ -x "$PYTHON" ] || { echo "Falta o venv em $SERVICO/.venv: rode deploy/forecast-install.sh" >&2; exit 1; }

valor_do_env() {
    [ -f "$RAIZ/.env" ] || return 0
    grep -E "^$1=" "$RAIZ/.env" | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//" || true
}

FORECAST_TOKEN="$(valor_do_env FORECAST_TOKEN)"
export FORECAST_TOKEN
FORECAST_PORT="$(valor_do_env FORECAST_PORT)"
export FORECAST_MODEL_DIR="${FORECAST_MODEL_DIR:-$RAIZ/storage/app/private/forecast/models}"

cd "$SERVICO"
exec "$PYTHON" -m uvicorn forecast.api:app --host 127.0.0.1 --port "${FORECAST_PORT:-8001}"
