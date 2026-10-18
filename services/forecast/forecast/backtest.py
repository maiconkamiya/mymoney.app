"""Backtest walk-forward: treina no passado, testa no bloco seguinte, nunca embaralha.

Entre o fim do treino e o início do teste há uma folga de `horizon` velas (purge): o rótulo
da última vela de treino olha `horizon` velas à frente e, sem a folga, vazaria o teste.
"""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd

from .features import FEATURES
from .model import new_classifier


@dataclass
class BacktestConfig:
    horizon: int = 15
    folds: int = 4
    min_train: int = 500
    threshold: float = 0.55  # só opera se P(alta) >= limiar ou <= 1 - limiar
    fee_bps: float = 20.0  # custo de ida e volta, em pontos-base
    min_trades: int = 30
    min_edge: float = 0.01  # quanto a acurácia precisa superar a linha de base


def _sorted_dataset(dataset: pd.DataFrame) -> pd.DataFrame:
    """Ordena por tempo: o corte walk-forward é cronológico entre todos os ativos."""
    return dataset.sort_values("open_at").reset_index(drop=True)


def walk_forward(
    dataset: pd.DataFrame,
    config: BacktestConfig,
    features: list[str] | None = None,
    make_classifier: Callable[[], Any] = new_classifier,
    trade_filter: Callable[[pd.DataFrame], np.ndarray] | None = None,
) -> dict:
    """`dataset` tem as colunas FEATURES, `label`, `fwd_return` e `open_at`.

    `features`, `make_classifier` e `trade_filter` existem para os experimentos
    (`forecast.experiments`); o treino de produção usa os padrões. `trade_filter` recebe o trecho
    de teste e devolve, por linha, se a operação é permitida.
    """
    features = features or FEATURES
    data = _sorted_dataset(dataset.dropna(subset=["label", "fwd_return"]))
    n = len(data)
    if n < config.min_train + config.folds * 50:
        return {"ok": False, "reason": f"poucos dados para validar ({n} linhas)", "rows": n}

    test_size = (n - config.min_train) // config.folds
    probs, labels, returns, allowed, fold_sizes = [], [], [], [], []
    train_labels_mean = []

    for fold in range(config.folds):
        test_start = config.min_train + fold * test_size
        test_end = n if fold == config.folds - 1 else test_start + test_size
        train = data.iloc[: max(0, test_start - config.horizon)]
        test = data.iloc[test_start:test_end]
        if train["label"].nunique() < 2 or test.empty:
            continue
        clf = make_classifier().fit(train[features], train["label"])
        probs.append(clf.predict_proba(test[features])[:, 1])
        fold_sizes.append(len(test))
        allowed.append(trade_filter(test) if trade_filter else np.ones(len(test), dtype=bool))
        labels.append(test["label"].to_numpy())
        returns.append(test["fwd_return"].to_numpy())
        train_labels_mean.append(np.full(len(test), train["label"].mean()))

    if not probs:
        return {"ok": False, "reason": "nenhuma janela de teste válida", "rows": n}

    p = np.concatenate(probs)
    y = np.concatenate(labels)
    r = np.concatenate(returns)
    base = np.concatenate(train_labels_mean)
    ok = np.concatenate(allowed)

    accuracy = float(((p > 0.5) == (y == 1)).mean())
    # Linha de base: sempre apostar na classe majoritária do treino.
    baseline = float(((base > 0.5) == (y == 1)).mean())
    brier = float(((p - y) ** 2).mean())
    brier_base = float(((base - y) ** 2).mean())

    long_, short_ = p >= config.threshold, p <= 1 - config.threshold
    traded = (long_ | short_) & ok
    side = np.where(long_, 1.0, np.where(short_, -1.0, 0.0))
    net = side[traded] * r[traded] - config.fee_bps / 10_000
    trades = int(traded.sum())

    fold_net = []
    start = 0
    for size in fold_sizes:
        sel = traded[start : start + size]
        fold_net.append(
            round(float((side[start : start + size][sel] * r[start : start + size][sel] - config.fee_bps / 10_000).mean()), 6)
            if sel.any()
            else None
        )
        start += size

    metrics = {
        "ok": True,
        "rows": n,
        "test_rows": int(len(y)),
        "accuracy": round(accuracy, 4),
        "baseline_accuracy": round(baseline, 4),
        "brier": round(brier, 5),
        "brier_baseline": round(brier_base, 5),
        "trades": trades,
        "hit_rate": round(float((net > 0).mean()), 4) if trades else None,
        "avg_net_return": round(float(net.mean()), 6) if trades else None,
        "total_net_return": round(float(net.sum()), 4) if trades else None,
        "fold_avg_net_return": fold_net,
        "fee_bps": config.fee_bps,
        "threshold": config.threshold,
        "horizon": config.horizon,
    }
    metrics["validated"] = bool(
        trades >= config.min_trades
        and accuracy >= baseline + config.min_edge
        and brier < brier_base
        and net.mean() > 0
    )
    return metrics
