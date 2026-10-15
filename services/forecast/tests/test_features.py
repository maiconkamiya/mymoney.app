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
