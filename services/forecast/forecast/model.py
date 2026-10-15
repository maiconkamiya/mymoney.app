"""Modelo de previsão: classificador de gradiente que estima P(alta) em `horizon` velas."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

import joblib
import pandas as pd
from sklearn.ensemble import HistGradientBoostingClassifier

from .features import FEATURES

MODEL_FILE = "model.joblib"
META_FILE = "meta.json"


def new_classifier() -> HistGradientBoostingClassifier:
    # Raso e regularizado de propósito: o sinal em velas curtas é fraco e o risco é decorar o ruído.
    return HistGradientBoostingClassifier(
        max_depth=3,
        learning_rate=0.05,
        max_iter=150,
        min_samples_leaf=50,
        l2_regularization=1.0,
        random_state=7,
    )


@dataclass
class Forecast:
    probability_up: float
    confidence: float
    validated: bool

    @property
    def direction(self) -> str:
        if not self.validated or self.confidence <= 0:
            return "neutral"
        return "up" if self.probability_up > 0.5 else "down"


@dataclass
class ForecastModel:
    classifier: HistGradientBoostingClassifier
    horizon: int
    version: str
    validated: bool
    metrics: dict = field(default_factory=dict)
    trained_at: str = field(default_factory=lambda: datetime.now(timezone.utc).isoformat())

    def predict(self, row: pd.DataFrame) -> Forecast:
        p_up = float(self.classifier.predict_proba(row[FEATURES])[0][1])
        # Confiança = distância até 50%, limitada; modelo sem validação não tem confiança nenhuma.
        confidence = min(0.9, abs(2 * p_up - 1)) if self.validated else 0.0
        return Forecast(probability_up=p_up, confidence=confidence, validated=self.validated)

    def save(self, directory: Path) -> None:
        directory.mkdir(parents=True, exist_ok=True)
        joblib.dump(self.classifier, directory / MODEL_FILE)
        meta = {
            "horizon": self.horizon,
            "version": self.version,
            "validated": self.validated,
            "metrics": self.metrics,
            "trained_at": self.trained_at,
        }
        (directory / META_FILE).write_text(json.dumps(meta, indent=2, ensure_ascii=False))

    @classmethod
    def load(cls, directory: Path) -> "ForecastModel | None":
        model_path, meta_path = directory / MODEL_FILE, directory / META_FILE
        if not (model_path.exists() and meta_path.exists()):
            return None
        meta = json.loads(meta_path.read_text())
        return cls(
            classifier=joblib.load(model_path),
            horizon=meta["horizon"],
            version=meta["version"],
            validated=meta["validated"],
            metrics=meta.get("metrics", {}),
            trained_at=meta["trained_at"],
        )
