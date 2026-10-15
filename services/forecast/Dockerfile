FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY forecast ./forecast
ENV FORECAST_MODEL_DIR=/models
VOLUME /models
EXPOSE 8001

CMD ["uvicorn", "forecast.api:app", "--host", "0.0.0.0", "--port", "8001"]
