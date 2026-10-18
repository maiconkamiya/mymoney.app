"""Experimentos para a issue #121: quais mudanças fazem o modelo passar no backtest.

    python -m forecast.experiments --candles candles.csv [--news news.csv] [--fee-bps 20]

Cada variação roda no mesmo backtest walk-forward de `forecast.train`, com os mesmos critérios de
validação e o mesmo custo; nada aqui afrouxa o portão nem grava modelo. A tabela mostra, além do
resultado agregado, o retorno líquido de cada janela: uma variação que só ganha em uma janela é
sorte de regime, não vantagem.

Cuidado com a comparação múltipla: quanto mais variações, maior a chance de uma passar por acaso.
Por isso a tabela marca "validated" mas a conclusão só vale se a variação repetir o resultado
em dados novos (rode de novo uma semana depois, com velas que o primeiro teste não viu).
"""

from __future__ import annotations

import argparse
import math
import sys
from collections.abc import Callable
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression

from .backtest import BacktestConfig, walk_forward
from .data import load_dataset
from .features import EXTRA_FEATURES, FEATURES
from .model import new_classifier


@dataclass
class Variation:
    name: str
    horizon: int = 15
    threshold: float = 0.55
    extra_features: bool = False
    calibrate: bool = False
    volatility_filter: bool = False
    options: dict = field(default_factory=dict)


class PlattCalibrated:
    """Treina nos primeiros 80% do treino e calibra (Platt) nos 20% finais, em ordem cronológica.

    As probabilidades do gradiente costumam sair confiantes demais; a calibração as aproxima da
    frequência real, o que reduz o Brier e faz o limiar de operação significar algo.
    """

    def __init__(self, calibration_share: float = 0.2):
        self.calibration_share = calibration_share

    def fit(self, features: pd.DataFrame, label: pd.Series) -> "PlattCalibrated":
        cut = int(len(features) * (1 - self.calibration_share))
        self.base = new_classifier().fit(features.iloc[:cut], label.iloc[:cut])
        raw = self._logit(self.base.predict_proba(features.iloc[cut:])[:, 1])
        self.platt = LogisticRegression().fit(raw, label.iloc[cut:])
        return self

    def predict_proba(self, features: pd.DataFrame) -> np.ndarray:
        raw = self._logit(self.base.predict_proba(features)[:, 1])
        return self.platt.predict_proba(raw)

    @staticmethod
    def _logit(p: np.ndarray) -> np.ndarray:
        p = np.clip(p, 1e-6, 1 - 1e-6)
        return np.log(p / (1 - p)).reshape(-1, 1)


def calibrated_classifier() -> PlattCalibrated:
    return PlattCalibrated()


def volatility_gate(horizon: int, fee_bps: float) -> Callable[[pd.DataFrame], np.ndarray]:
    """Só opera quando o movimento típico do horizonte (vol_15 · √horizonte) já cobre o custo."""
    cost = fee_bps / 10_000

    def allowed(test: pd.DataFrame) -> np.ndarray:
        return (test["vol_15"] * math.sqrt(horizon) >= cost).to_numpy()

    return allowed


def variations() -> list[Variation]:
    return [
        Variation("base (como em produção)"),
        Variation("calibrado", calibrate=True),
        Variation("calibrado, limiar 0,60", calibrate=True, threshold=0.60),
        Variation("calibrado, limiar 0,65", calibrate=True, threshold=0.65),
        Variation("filtro de volatilidade", volatility_filter=True),
        Variation("variáveis extras", extra_features=True),
        Variation("extras + calibrado", extra_features=True, calibrate=True),
        Variation("horizonte 30", horizon=30),
        Variation("horizonte 60", horizon=60),
        Variation("horizonte 240", horizon=240),
        Variation("h30: extras + calibrado + volatilidade", horizon=30, extra_features=True, calibrate=True, volatility_filter=True),
        Variation("h60: extras + calibrado + volatilidade", horizon=60, extra_features=True, calibrate=True, volatility_filter=True),
        Variation("h240: extras + calibrado + volatilidade", horizon=240, extra_features=True, calibrate=True, volatility_filter=True),
    ]


def run_variation(variation: Variation, candles: Path, news: Path | None, fee_bps: float, folds: int, cache: dict) -> dict:
    key = (variation.horizon, variation.extra_features)
    if key not in cache:
        cache[key] = load_dataset(candles, news, variation.horizon, extra=variation.extra_features)
    dataset = cache[key]
    config = BacktestConfig(
        horizon=variation.horizon, folds=folds, fee_bps=fee_bps, threshold=variation.threshold
    )
    return walk_forward(
        dataset,
        config,
        features=[*FEATURES, *(EXTRA_FEATURES if variation.extra_features else [])],
        make_classifier=calibrated_classifier if variation.calibrate else new_classifier,
        trade_filter=volatility_gate(variation.horizon, fee_bps) if variation.volatility_filter else None,
    )


def _pct(value: float | None, digits: int = 3) -> str:
    return "-" if value is None else f"{value * 100:.{digits}f}%"


def format_table(rows: list[tuple[Variation, dict]]) -> str:
    header = f"{'variação':<42} {'acur.':>6} {'base':>6} {'brier':>7} {'b.base':>7} {'oper.':>7} {'líq./op.':>9}  janelas (líq./op.)  validado"
    lines = [header, "-" * len(header)]
    for variation, m in rows:
        if not m.get("ok"):
            lines.append(f"{variation.name:<42} {m.get('reason', 'sem resultado')}")
            continue
        folds = " ".join(_pct(v, 2).rjust(7) for v in m["fold_avg_net_return"])
        lines.append(
            f"{variation.name:<42} {m['accuracy'] * 100:>5.1f}% {m['baseline_accuracy'] * 100:>5.1f}% "
            f"{m['brier']:>7.4f} {m['brier_baseline']:>7.4f} {m['trades']:>7} {_pct(m['avg_net_return']):>9}  {folds}  "
            f"{'SIM' if m['validated'] else 'não'}"
        )
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Compara variações do modelo no mesmo backtest")
    parser.add_argument("--candles", required=True, type=Path)
    parser.add_argument("--news", type=Path)
    parser.add_argument("--folds", type=int, default=4)
    parser.add_argument("--fee-bps", type=float, default=20.0)
    args = parser.parse_args(argv)

    cache: dict = {}
    rows = []
    for variation in variations():
        print(f"rodando: {variation.name}", file=sys.stderr, flush=True)
        rows.append((variation, run_variation(variation, args.candles, args.news, args.fee_bps, args.folds, cache)))

    print(format_table(rows))
    winners = [v.name for v, m in rows if m.get("validated")]
    print()
    if winners:
        print("Passaram nos quatro critérios: " + "; ".join(winners))
        print("Confirme com velas novas antes de adotar (comparação múltipla).")
    else:
        print("Nenhuma variação passou nos quatro critérios. O modelo segue não validado.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
