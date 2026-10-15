<?php

namespace App\Forecast;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the Python forecast service for the probability of an up or down move.
 * Any problem (disabled, down, slow, no trained model, unvalidated model)
 * returns null, so the scenario goes without a forecast and the quant agent
 * votes hold.
 */
class ForecastClient
{
    public function enabled(): bool
    {
        return (bool) config('forecast.enabled');
    }

    /**
     * @param  list<array<string, mixed>>  $candles  Oldest first.
     * @param  list<array<string, mixed>>  $news
     * @return array{direction: string, probability_up: float, probability_down: float, confidence: float, horizon_candles: int, model_version: string}|null
     */
    public function predict(string $symbol, string $market, array $candles, array $news): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = $this->request()->post('/predict', [
                'symbol' => $symbol,
                'market' => $market,
                'candles' => array_map(fn (array $c) => [
                    'open' => $c['open'], 'high' => $c['high'], 'low' => $c['low'], 'close' => $c['close'], 'volume' => $c['volume'],
                ], $candles),
                'news' => array_map(fn (array $n) => [
                    'sentiment' => $n['sentiment'] ?? 0, 'relevance' => $n['relevance'] ?? 0,
                ], $news),
            ]);
        } catch (Throwable $e) {
            Log::warning('Forecast service unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::info('Forecast service gave no forecast', ['status' => $response->status()]);

            return null;
        }

        $data = $response->json();

        if (! is_array($data) || ! ($data['validated'] ?? false)
            || ! is_numeric($data['probability_up'] ?? null) || ! is_numeric($data['confidence'] ?? null)
            || ! in_array($data['direction'] ?? null, ['up', 'down', 'neutral'], true)) {
            return null;
        }

        $up = max(0.0, min(1.0, (float) $data['probability_up']));

        return [
            'direction' => $data['direction'],
            'probability_up' => $up,
            'probability_down' => 1 - $up,
            'confidence' => max(0.0, min(1.0, (float) $data['confidence'])),
            'horizon_candles' => (int) ($data['horizon_candles'] ?? 0),
            'model_version' => (string) ($data['model_version'] ?? ''),
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl((string) config('forecast.url'))
            ->acceptJson()
            ->timeout((int) config('forecast.timeout_seconds'));

        $token = config('forecast.token');

        return is_string($token) && $token !== '' ? $request->withToken($token) : $request;
    }
}
