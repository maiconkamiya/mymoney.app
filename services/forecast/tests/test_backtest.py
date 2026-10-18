import pandas as pd

from forecast.backtest import BacktestConfig, walk_forward
from forecast.data import load_dataset
from tests.conftest import synthetic_candles


def test_walk_forward_finds_edge_in_predictable_series(candles_csv):
    dataset = load_dataset(candles_csv, None, horizon=1)
    metrics = walk_forward(dataset, BacktestConfig(horizon=1, threshold=0.55, fee_bps=0))
    assert metrics["ok"]
    assert metrics["accuracy"] > metrics["baseline_accuracy"] + 0.05
    assert metrics["validated"] is True


def test_walk_forward_rejects_random_walk(tmp_path):
    frame = synthetic_candles(2500, seed=3, predictable=False)
    frame.insert(0, "timeframe", "1m")
    frame.insert(0, "symbol", "X")
    path = tmp_path / "c.csv"
    frame.to_csv(path, index=False)
    metrics = walk_forward(load_dataset(path, None, horizon=1), BacktestConfig(horizon=1, fee_bps=20))
    assert metrics["validated"] is False


def test_too_little_data_is_not_validated(tmp_path):
    frame = synthetic_candles(200)
    frame.insert(0, "timeframe", "1m")
    frame.insert(0, "symbol", "X")
    path = tmp_path / "c.csv"
    frame.to_csv(path, index=False)
    metrics = walk_forward(load_dataset(path, None, horizon=5), BacktestConfig(horizon=5))
    assert metrics["ok"] is False


def test_experiments_table_runs_every_variation(candles_csv):
    from forecast.experiments import Variation, format_table, run_variation

    rows = []
    for variation in (
        Variation("base"),
        Variation("completa", horizon=5, extra_features=True, calibrate=True, volatility_filter=True),
    ):
        rows.append((variation, run_variation(variation, candles_csv, None, 0.0, 4, {})))

    table = format_table(rows)
    assert "base" in table and "completa" in table
    assert all(len(m["fold_avg_net_return"]) == 4 for _, m in rows if m["ok"])


def test_experiments_survive_features_empty_in_the_first_window(tmp_path):
    from forecast.experiments import Variation, run_variation
    from tests.conftest import synthetic_candles

    # Vários ativos começando juntos: na primeira janela as variáveis de 240 velas ainda estão vazias.
    frames = []
    for i, symbol in enumerate(("AAAUSDT", "BBBUSDT", "CCCUSDT", "DDDUSDT")):
        frame = synthetic_candles(900, seed=i + 1)
        frame.insert(0, "timeframe", "1m")
        frame.insert(0, "symbol", symbol)
        frames.append(frame)
    path = tmp_path / "candles.csv"
    pd.concat(frames).to_csv(path, index=False)

    for variation in (
        Variation("extras", horizon=5, extra_features=True),
        Variation("extras calibrado", horizon=5, extra_features=True, calibrate=True),
    ):
        assert run_variation(variation, path, None, 0.0, 4, {})["ok"]
