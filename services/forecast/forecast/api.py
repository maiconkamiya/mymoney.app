"""API HTTP consumida pelo Laravel. POST /predict recebe o cenário e devolve a previsão."""

from __future__ import annotations

import os
from datetime import datetime, timezone
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from .features import MIN_CANDLES, build_features, candles_frame, live_sentiment
from .model import ForecastModel

app = FastAPI(title="MyMoney Forecast", version="1.0.0")

_loaded: dict = {"stamp": None, "model": None}


def model_dir() -> Path:
    return Path(os.getenv("FORECAST_MODEL_DIR", "models"))


def current_model() -> ForecastModel | None:
    """Recarrega quando um novo treino grava outra versão."""
    directory = model_dir()
    meta = directory / "meta.json"
    stamp = str(meta.stat().st_mtime) if meta.exists() else "none"
    key = f"{directory}:{stamp}"
    if _loaded["stamp"] != key:
        _loaded.update(stamp=key, model=ForecastModel.load(directory))
    return _loaded["model"]


def authorize(authorization: str | None = Header(default=None)) -> None:
    token = os.getenv("FORECAST_TOKEN")
    if token and authorization != f"Bearer {token}":
        raise HTTPException(status_code=401, detail="Token inválido")


class PredictRequest(BaseModel):
    symbol: str
    market: str | None = None
    candles: list[dict] = Field(default_factory=list, description="mais antigo primeiro")
    news: list[dict] = Field(default_factory=list)


@app.get("/health")
def health() -> dict:
    model = current_model()
    return {
        "status": "ok",
        "model": None if model is None else {
            "version": model.version,
            "validated": model.validated,
            "horizon": model.horizon,
            "trained_at": model.trained_at,
        },
    }


@app.post("/predict", dependencies=[Depends(authorize)])
def predict(request: PredictRequest) -> dict:
    model = current_model()
    if model is None:
        raise HTTPException(status_code=503, detail="Nenhum modelo treinado")
    if len(request.candles) < MIN_CANDLES:
        raise HTTPException(status_code=422, detail=f"São necessárias ao menos {MIN_CANDLES} velas")

    frame = candles_frame(request.candles)
    if len(frame) < MIN_CANDLES:
        raise HTTPException(status_code=422, detail="Velas inválidas")

    features = build_features(frame, live_sentiment(request.news)).tail(1)
    if features.isna().all(axis=None):
        raise HTTPException(status_code=422, detail="Não foi possível calcular as variáveis")

    forecast = model.predict(features)
    return {
        "symbol": request.symbol,
        "direction": forecast.direction,
        "probability_up": round(forecast.probability_up, 4),
        "probability_down": round(1 - forecast.probability_up, 4),
        "confidence": round(forecast.confidence, 4),
        "horizon_candles": model.horizon,
        "validated": forecast.validated,
        "model_version": model.version,
        "backtest": {
            key: model.metrics.get(key)
            for key in ("accuracy", "baseline_accuracy", "trades", "hit_rate", "avg_net_return")
        },
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }
