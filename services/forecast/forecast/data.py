"""Leitura dos candles e notícias exportados pelo Laravel (`php artisan forecast:train`)."""

from __future__ import annotations

from pathlib import Path

import pandas as pd

from .features import (
    FEATURES,
    add_label,
    build_features,
    forward_return,
    news_sentiment_series,
)


def load_dataset(candles_csv: Path, news_csv: Path | None, horizon: int, timeframe: str = "1m") -> pd.DataFrame:
    candles = pd.read_csv(candles_csv, parse_dates=["open_at"])
    candles = candles[candles["timeframe"] == timeframe]

    news = None
    if news_csv is not None and Path(news_csv).exists():
        news = pd.read_csv(news_csv, parse_dates=["fetched_at"])

    parts = []
    for symbol, group in candles.groupby("symbol"):
        group = group.sort_values("open_at").reset_index(drop=True)
        asset_news = news[news["symbol"] == symbol] if news is not None else None
        sentiment = news_sentiment_series(group["open_at"], asset_news)
        part = build_features(group, sentiment)
        part["label"] = add_label(group, horizon)
        part["fwd_return"] = forward_return(group, horizon)
        part["open_at"] = group["open_at"]
        part["symbol"] = symbol
        parts.append(part)

    if not parts:
        return pd.DataFrame(columns=[*FEATURES, "label", "fwd_return", "open_at", "symbol"])
    return pd.concat(parts, ignore_index=True)
