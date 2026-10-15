import json

import pytest
from fastapi.testclient import TestClient

from forecast import api
from forecast.train import main as train
from tests.conftest import synthetic_candles


@pytest.fixture
def client(tmp_path, candles_csv, monkeypatch):
    monkeypatch.setenv("FORECAST_MODEL_DIR", str(tmp_path / "models"))
    monkeypatch.delenv("FORECAST_TOKEN", raising=False)
    api._loaded.update(stamp=None, model=None)
    assert train(["--candles", str(candles_csv), "--horizon", "1", "--fee-bps", "0"]) == 0
    return TestClient(api.app)


def scenario(n=60):
    frame = synthetic_candles(n, seed=9)
    candles = [{k: float(r[k]) for k in ("open", "high", "low", "close", "volume")} for _, r in frame.iterrows()]
    return {"symbol": "BTCUSDT", "market": "crypto", "candles": candles, "news": [{"sentiment": 0.4, "relevance": 0.9}]}


def test_predict_returns_probability_and_confidence(client):
    response = client.post("/predict", json=scenario(80))
    assert response.status_code == 200
    body = response.json()
    assert body["direction"] in {"up", "down", "neutral"}
    assert body["probability_up"] + body["probability_down"] == pytest.approx(1, abs=1e-3)
    assert 0 <= body["confidence"] <= 0.9
    assert body["horizon_candles"] == 1


def test_predict_needs_enough_candles(client):
    assert client.post("/predict", json=scenario(10)).status_code == 422


def test_no_model_means_503(tmp_path, monkeypatch):
    monkeypatch.setenv("FORECAST_MODEL_DIR", str(tmp_path / "vazio"))
    api._loaded.update(stamp=None, model=None)
    assert TestClient(api.app).post("/predict", json=scenario(80)).status_code == 503


def test_token_is_enforced_when_configured(client, monkeypatch):
    monkeypatch.setenv("FORECAST_TOKEN", "segredo")
    assert client.post("/predict", json=scenario(80)).status_code == 401
    ok = client.post("/predict", json=scenario(80), headers={"Authorization": "Bearer segredo"})
    assert ok.status_code == 200


def test_unvalidated_model_has_zero_confidence(client, tmp_path):
    meta_path = tmp_path / "models" / "meta.json"
    meta = json.loads(meta_path.read_text())
    meta["validated"] = False
    meta_path.write_text(json.dumps(meta))
    api._loaded.update(stamp=None, model=None)
    body = client.post("/predict", json=scenario(80)).json()
    assert body["confidence"] == 0
    assert body["direction"] == "neutral"
