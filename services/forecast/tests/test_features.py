import numpy as np
import pandas as pd

from forecast.features import FEATURES, add_label, build_features, news_sentiment_series
from tests.conftest import synthetic_candles


def test_features_do_not_use_the_future():
    frame = synthetic_candles(200)
    full = build_features(frame)
    truncated = build_features(frame.iloc[:120])
    pd.testing.assert_frame_equal(full.iloc[:120], truncated)


def test_label_only_exists_with_a_future():
    frame = synthetic_candles(50)
    label = add_label(frame, horizon=5)
    assert label.iloc[-5:].isna().all()
    assert label.iloc[:-5].notna().all()
    assert list(build_features(frame).columns) == FEATURES


def test_news_sentiment_ignores_news_not_yet_fetched():
    times = pd.Series(pd.date_range("2026-01-01 10:00", periods=3, freq="h"))
    news = pd.DataFrame(
        {"fetched_at": [pd.Timestamp("2026-01-01 11:30")], "sentiment": [0.8], "relevance": [1.0]}
    )
    result = news_sentiment_series(times, news)
    assert result.tolist() == [0.0, 0.0, 0.8]


def test_news_sentiment_expires_after_window():
    times = pd.Series([pd.Timestamp("2026-01-04 10:00")])
    news = pd.DataFrame(
        {"fetched_at": [pd.Timestamp("2026-01-01 10:00")], "sentiment": [0.8], "relevance": [1.0]}
    )
    assert news_sentiment_series(times, news, window_hours=48).iloc[0] == 0.0
    assert not np.isnan(news_sentiment_series(times, None).iloc[0])


def test_news_sentiment_accepts_mixed_timezone_strings():
    # O CSV do Laravel pode trazer fusos diferentes; o pandas lê isso como `object`.
    times = pd.Series(["2026-01-01T10:00:00+00:00", "2026-01-01T12:00:00-03:00", "2026-01-01T16:00:00+00:00"])
    news = pd.DataFrame(
        {"fetched_at": ["2026-01-01T11:30:00+00:00"], "sentiment": [0.8], "relevance": [1.0]}
    )
    result = news_sentiment_series(times, news)
    assert result.tolist() == [0.0, 0.8, 0.8]


def test_load_dataset_reads_mixed_timezone_csv(tmp_path):
    from forecast.data import load_dataset

    frame = synthetic_candles(120)
    frame["symbol"] = "BTCUSDT"
    frame["timeframe"] = "1m"
    start = pd.Timestamp("2026-01-01 00:00", tz="UTC")
    stamps = [(start + pd.Timedelta(minutes=i)).isoformat() for i in range(len(frame))]
    stamps[5] = (start + pd.Timedelta(minutes=5)).tz_convert("America/Sao_Paulo").isoformat()
    frame["open_at"] = stamps
    path = tmp_path / "candles.csv"
    frame.to_csv(path, index=False)
    news_path = tmp_path / "news.csv"
    pd.DataFrame(
        {"symbol": ["BTCUSDT"], "fetched_at": ["2026-01-01T00:30:00+00:00"], "sentiment": [0.5], "relevance": [1.0]}
    ).to_csv(news_path, index=False)

    dataset = load_dataset(path, news_path, horizon=5)
    assert len(dataset) == 120
    assert dataset["open_at"].dtype.kind == "M"
