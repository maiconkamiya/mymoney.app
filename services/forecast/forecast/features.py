"""Features e rótulo do modelo.

Regra de ouro: a feature da linha t usa só dados até o fim da vela t. O rótulo olha
`horizon` velas à frente e só existe no treino; a previsão ao vivo nunca o calcula.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

FEATURES = [
    "ret_1",
    "ret_5",
    "ret_15",
    "vol_15",
    "vol_60",
    "rsi_14",
    "ma_ratio",
    "volume_ratio",
    "range_pct",
    "sentiment",
]

# Velas mínimas para todas as janelas terem dados.
MIN_CANDLES = 61


def candles_frame(candles: list[dict]) -> pd.DataFrame:
    """Converte a lista de candles do cenário em DataFrame ordenado do mais antigo ao mais novo."""
    frame = pd.DataFrame(candles)
    for column in ("open", "high", "low", "close", "volume"):
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    return frame.dropna(subset=["close"]).reset_index(drop=True)


def _rsi(close: pd.Series, window: int = 14) -> pd.Series:
    delta = close.diff()
    gain = delta.clip(lower=0).rolling(window).mean()
    loss = (-delta.clip(upper=0)).rolling(window).mean()
    rs = gain / loss.replace(0, np.nan)
    rsi = 100 - 100 / (1 + rs)
    # Sem perdas na janela: RSI 100; sem ganhos nem perdas: neutro.
    rsi = rsi.where(~((loss == 0) & (gain > 0)), 100.0)
    return rsi.where(~((loss == 0) & (gain == 0)), 50.0)


def build_features(frame: pd.DataFrame, sentiment: pd.Series | float = 0.0) -> pd.DataFrame:
    """Calcula as features vela a vela. `sentiment` é a média do sentimento das notícias já publicadas."""
    close = frame["close"]
    log_ret = np.log(close).diff()

    out = pd.DataFrame(index=frame.index)
    out["ret_1"] = close.pct_change(1)
    out["ret_5"] = close.pct_change(5)
    out["ret_15"] = close.pct_change(15)
    out["vol_15"] = log_ret.rolling(15).std()
    out["vol_60"] = log_ret.rolling(60).std()
    out["rsi_14"] = _rsi(close) / 100.0
    out["ma_ratio"] = close / close.rolling(30).mean() - 1
    volume_mean = frame["volume"].rolling(30).mean().replace(0, np.nan)
    out["volume_ratio"] = frame["volume"] / volume_mean
    out["range_pct"] = (frame["high"] - frame["low"]) / close
    out["sentiment"] = sentiment if isinstance(sentiment, pd.Series) else float(sentiment)
    return out.replace([np.inf, -np.inf], np.nan)[FEATURES]


def add_label(frame: pd.DataFrame, horizon: int) -> pd.Series:
    """1 se o fechamento `horizon` velas à frente é maior que o atual; NaN nas últimas velas."""
    future = frame["close"].shift(-horizon)
    label = (future > frame["close"]).astype(float)
    return label.where(future.notna())


def forward_return(frame: pd.DataFrame, horizon: int) -> pd.Series:
    return frame["close"].shift(-horizon) / frame["close"] - 1


def news_sentiment_series(
    candle_times: pd.Series, news: pd.DataFrame | None, window_hours: int = 48
) -> pd.Series:
    """Sentimento médio ponderado pela relevância das notícias dos últimos `window_hours`.

    Usa `fetched_at` (quando o sistema soube da notícia), nunca a data de publicação, e só
    notícias com `fetched_at <= vela`: o mesmo filtro do cenário ao vivo.
    """
    if news is None or news.empty:
        return pd.Series(0.0, index=candle_times.index)

    news = news.sort_values("fetched_at")
    times = news["fetched_at"].to_numpy()
    weighted = (news["sentiment"] * news["relevance"]).to_numpy()
    weights = news["relevance"].to_numpy()
    cum_w = np.concatenate([[0.0], np.cumsum(weighted)])
    cum_r = np.concatenate([[0.0], np.cumsum(weights)])

    stamps = candle_times.to_numpy()
    hi = np.searchsorted(times, stamps, side="right")
    lo = np.searchsorted(times, stamps - np.timedelta64(window_hours, "h"), side="left")
    lo = np.minimum(lo, hi)
    num = cum_w[hi] - cum_w[lo]
    den = cum_r[hi] - cum_r[lo]
    return pd.Series(np.where(den > 0, num / np.where(den > 0, den, 1), 0.0), index=candle_times.index)


def live_sentiment(news: list[dict]) -> float:
    """Sentimento do cenário ao vivo, calculado como na série de treino."""
    total = sum(float(n.get("sentiment") or 0) * float(n.get("relevance") or 0) for n in news)
    weight = sum(float(n.get("relevance") or 0) for n in news)
    return total / weight if weight > 0 else 0.0
