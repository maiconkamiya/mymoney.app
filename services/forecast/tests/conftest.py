import numpy as np
import pandas as pd
import pytest


def synthetic_candles(n: int = 2500, seed: int = 1, predictable: bool = True) -> pd.DataFrame:
    """Série com momentum fraco (se `predictable`): o retorno seguinte depende do anterior."""
    rng = np.random.default_rng(seed)
    noise = rng.normal(0, 0.001, n)
    returns = np.zeros(n)
    for i in range(1, n):
        returns[i] = (0.5 * returns[i - 1] if predictable else 0.0) + noise[i]
    close = 100 * np.exp(np.cumsum(returns))
    open_ = np.concatenate([[100], close[:-1]])
    return pd.DataFrame(
        {
            "open_at": pd.date_range("2026-01-01", periods=n, freq="min"),
            "open": open_,
            "high": np.maximum(open_, close) * 1.0002,
            "low": np.minimum(open_, close) * 0.9998,
            "close": close,
            "volume": rng.uniform(1, 10, n),
        }
    )


@pytest.fixture
def candles_csv(tmp_path):
    frame = synthetic_candles()
    frame.insert(0, "timeframe", "1m")
    frame.insert(0, "symbol", "BTCUSDT")
    path = tmp_path / "candles.csv"
    frame.to_csv(path, index=False)
    return path
