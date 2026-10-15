<?php

namespace Tests\Feature\Forecast;

use App\Forecast\ForecastClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForecastClientTest extends TestCase
{
    private const CANDLE = ['id' => 'x', 'open' => 1.0, 'high' => 2.0, 'low' => 0.5, 'close' => 1.5, 'volume' => 10.0];

    private function ok(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'up', 'probability_up' => 0.62, 'probability_down' => 0.38, 'confidence' => 0.24,
            'horizon_candles' => 15, 'validated' => true, 'model_version' => 'v1',
        ], $overrides);
    }

    private function predict(): ?array
    {
        return app(ForecastClient::class)->predict('BTCUSDT', 'crypto', [self::CANDLE], [['id' => 'n', 'sentiment' => 0.4, 'relevance' => 0.9, 'title' => 'x']]);
    }

    public function test_is_off_by_default_and_never_calls_the_service()
    {
        Http::fake();

        $this->assertFalse(config('forecast.enabled'));
        $this->assertNull($this->predict());
        Http::assertNothingSent();
    }

    public function test_returns_the_forecast_when_enabled()
    {
        config(['forecast.enabled' => true, 'forecast.url' => 'http://forecast.test', 'forecast.token' => 'segredo']);
        Http::fake(['forecast.test/predict' => Http::response($this->ok())]);

        $forecast = $this->predict();

        $this->assertSame('up', $forecast['direction']);
        $this->assertSame(0.62, $forecast['probability_up']);
        $this->assertSame(0.24, $forecast['confidence']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer segredo')
            && $request['symbol'] === 'BTCUSDT'
            && $request['candles'][0] === ['open' => 1.0, 'high' => 2.0, 'low' => 0.5, 'close' => 1.5, 'volume' => 10.0]
            && $request['news'][0] === ['sentiment' => 0.4, 'relevance' => 0.9]);
    }

    public function test_falls_back_to_no_forecast_when_the_service_fails()
    {
        config(['forecast.enabled' => true, 'forecast.url' => 'http://forecast.test']);

        Http::fake(['forecast.test/*' => Http::response(['detail' => 'Nenhum modelo treinado'], 503)]);
        $this->assertNull($this->predict());

        Http::fake(['forecast.test/*' => fn () => throw new ConnectionException('fora do ar')]);
        $this->assertNull($this->predict());
    }

    public function test_ignores_unvalidated_or_malformed_answers()
    {
        config(['forecast.enabled' => true, 'forecast.url' => 'http://forecast.test']);

        Http::fake(['forecast.test/*' => Http::response($this->ok(['validated' => false]))]);
        $this->assertNull($this->predict());

        Http::fake(['forecast.test/*' => Http::response(['direction' => 'sideways'])]);
        $this->assertNull($this->predict());
    }
}
