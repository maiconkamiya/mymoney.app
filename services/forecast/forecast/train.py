"""Comando de treino e backtest.

    python -m forecast.train --candles candles.csv [--news news.csv] [--horizon 15]

Roda o backtest walk-forward; só então treina o modelo final com todos os dados e grava em
FORECAST_MODEL_DIR. Se o backtest não superar a linha de base, o modelo é gravado como não
validado e a API devolve confiança zero (o agente quantitativo vota "aguardar").
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

from .backtest import BacktestConfig, walk_forward
from .data import load_dataset
from .features import FEATURES
from .model import ForecastModel, new_classifier


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Treina e valida o modelo de previsão")
    parser.add_argument("--candles", required=True, type=Path)
    parser.add_argument("--news", type=Path)
    parser.add_argument("--horizon", type=int, default=15, help="velas à frente")
    parser.add_argument("--timeframe", default="1m")
    parser.add_argument("--folds", type=int, default=4)
    parser.add_argument("--fee-bps", type=float, default=20.0)
    parser.add_argument("--threshold", type=float, default=0.55)
    parser.add_argument("--model-dir", type=Path, default=Path(os.getenv("FORECAST_MODEL_DIR", "models")))
    args = parser.parse_args(argv)

    dataset = load_dataset(args.candles, args.news, args.horizon, args.timeframe)
    config = BacktestConfig(horizon=args.horizon, folds=args.folds, fee_bps=args.fee_bps, threshold=args.threshold)
    metrics = walk_forward(dataset, config)

    print(json.dumps(metrics, indent=2, ensure_ascii=False))

    trainable = dataset.dropna(subset=["label"])
    if trainable["label"].nunique() < 2:
        print("Dados insuficientes para treinar.", file=sys.stderr)
        return 1

    classifier = new_classifier().fit(trainable[FEATURES], trainable["label"])
    version = datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    model = ForecastModel(
        classifier=classifier,
        horizon=args.horizon,
        version=version,
        validated=bool(metrics.get("validated", False)),
        metrics=metrics,
    )
    model.save(args.model_dir)
    status = "validado" if model.validated else "NÃO validado (confiança zero na API)"
    print(f"Modelo {version} gravado em {args.model_dir}: {status}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
