<?php

namespace Tests\Feature\Forecast;

use App\Agents\ScenarioBuilder;
use App\Agents\SignalScanner;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class ScenarioForecastTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private function signal(): Signal
    {
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($asset, 100);
        Portfolio::factory()->create(['market' => $asset->market]);

        return app(SignalScanner::class)->scan($asset)[0];
    }

    public function test_scenario_carries_the_forecast_when_the_service_is_on()
    {
        config(['forecast.enabled' => true, 'forecast.url' => 'http://forecast.test']);
        Http::fake(['forecast.test/predict' => Http::response([
            'direction' => 'down', 'probability_up' => 0.3, 'probability_down' => 0.7, 'confidence' => 0.4,
            'horizon_candles' => 15, 'validated' => true, 'model_version' => 'v1',
        ])]);

        $scenario = app(ScenarioBuilder::class)->build($this->signal());

        $this->assertSame('down', $scenario->forecast['direction']);
        $this->assertSame(0.4, $scenario->forecast['confidence']);
    }

    public function test_forecast_gets_61_candles_while_the_agents_keep_60()
    {
        config(['forecast.enabled' => true, 'forecast.url' => 'http://forecast.test']);
        Http::fake(['forecast.test/predict' => Http::response([
            'direction' => 'up', 'probability_up' => 0.6, 'probability_down' => 0.4, 'confidence' => 0.2,
            'horizon_candles' => 15, 'validated' => true, 'model_version' => 'v1',
        ])]);
        $asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->history($asset, 100, 120);
        $this->quote($asset, 100);
        Portfolio::factory()->create(['market' => $asset->market]);
        $signal = app(SignalScanner::class)->scan($asset)[0];

        $scenario = app(ScenarioBuilder::class)->build($signal);

        $this->assertCount(60, $scenario->candles);
        Http::assertSent(fn ($request) => $request->url() === 'http://forecast.test/predict' && count($request['candles']) === 61);
    }

    public function test_scenario_has_no_forecast_when_the_service_is_off_or_down()
    {
        Http::fake();
        $this->assertNull(app(ScenarioBuilder::class)->build($this->signal())->forecast);
        Http::assertNothingSent();

        config(['forecast.enabled' => true, 'forecast.url' => 'http://forecast.test']);
        Http::fake(['forecast.test/*' => Http::response('', 500)]);
        $this->assertNull(app(ScenarioBuilder::class)->build($this->signal())->forecast);
    }
}
