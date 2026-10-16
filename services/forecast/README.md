# Serviço de previsão (agente quantitativo)

Serviço Python pequeno que estima a probabilidade de **alta ou baixa** de um ativo nas próximas `horizon` velas. O Laravel o consulta ao montar o cenário (`Scenario::forecast`) e o agente quantitativo traduz a previsão em voto. Ele é **uma voz entre várias**: a guarda de risco continua com a palavra final.

- Variáveis: retornos de 1, 5 e 15 velas, volatilidade, RSI, distância da média, volume relativo, amplitude e **sentimento das notícias** (média ponderada pela relevância, só notícias já conhecidas na vela, via `fetched_at`).
- Modelo: `HistGradientBoostingClassifier` (scikit-learn), raso e regularizado.
- Validação: backtest **walk-forward** (treina no passado, testa no bloco seguinte, folga de `horizon` velas contra vazamento), com custo de ida e volta. O modelo só é marcado `validated` se superar a linha de base (acurácia e Brier) com retorno líquido positivo e operações suficientes. Modelo não validado devolve confiança **0**, e o agente vota "aguardar".

## Rodando

```bash
cd services/forecast
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
pytest                                  # testes (sem rede)

# Treino e backtest a partir dos candles do Laravel (exporta, treina e grava o modelo)
php artisan forecast:train --horizon=15

# Subir a API
FORECAST_MODEL_DIR=models uvicorn forecast.api:app --port 8001
```

Com Docker:

```bash
docker build -t mymoney-forecast services/forecast
docker run -p 8001:8001 -v $PWD/storage/app/forecast/models:/models mymoney-forecast
```

Treino manual com CSVs: `python -m forecast.train --candles candles.csv --news news.csv --horizon 15`.
Colunas: candles = `symbol,timeframe,open_at,open,high,low,close,volume`; notícias = `symbol,fetched_at,sentiment,relevance`.

## API

- `GET /health`: estado e versão do modelo.
- `POST /predict` com `{symbol, market, candles[], news[]}` (candles do mais antigo ao mais novo, ao menos 61):

```json
{
    "direction": "up",
    "probability_up": 0.61,
    "probability_down": 0.39,
    "confidence": 0.22,
    "horizon_candles": 15,
    "validated": true,
    "model_version": "20261015120000",
    "backtest": {}
}
```

`FORECAST_TOKEN` (opcional) exige `Authorization: Bearer <token>`. Sem modelo treinado a API responde 503, e o Laravel trata como "sem previsão".

## Laravel

Desligado por padrão: `FORECAST_ENABLED=false`. Ligue com `FORECAST_ENABLED=true`, `FORECAST_URL` e `FORECAST_TOKEN`. Serviço fora do ar, lento ou sem modelo: o cenário segue sem previsão e o agente quantitativo vota "aguardar".
