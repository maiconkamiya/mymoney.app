<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Candle;
use App\Models\Order;
use App\Models\Portfolio;
use App\Trading\Micro\MicroRanker;
use App\Trading\Micro\MicroRules;
use App\Trading\Micro\MicroSetup;
use App\Trading\Micro\MicroTrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tempo entre a leitura do gráfico e a compra das micro operações (#196).
 */
class MicroLatenciaTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->asset = Asset::factory()->create(['symbol' => 'DOGEUSDT', 'tick_size' => '0.01']);
    }

    /**
     * Same chart as MicroPrecoSugeridoTest: flat, one spike, then a slow climb near the support.
     */
    private function candles(float $end = 100.15): void
    {
        $rows = [];

        for ($i = 0; $i < 120; $i++) {
            $close = $i < 95 ? 100.0 : 100.0 + ($end - 100.0) * ($i - 94) / 25;
            $rows[] = [
                'asset_id' => $this->asset->id,
                'timeframe' => '1m',
                'open_at' => now()->startOfMinute()->subMinutes(119 - $i),
                'open' => $close,
                'high' => $close + 0.05 + ($i === 80 ? 0.9 : 0),
                'low' => $close - 0.05,
                'close' => $close,
                'volume' => 1,
            ];
        }

        Candle::insert($rows);
    }

    private function micro(): Portfolio
    {
        return Portfolio::factory()->create([
            'micro_enabled' => true,
            'micro_order_value' => 20,
            'micro_net_margin_pct' => 0.2,
            'micro_limit_seconds' => 60,
        ]);
    }

    public function test_each_buy_records_how_long_it_took_from_the_reading_to_the_answer()
    {
        $this->candles();
        $portfolio = $this->micro();

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        $buy = Order::sole();
        $log = AuditLog::where('action', 'micro.buy_latency')->sole();

        $this->assertSame($buy->id, $log->subject_id);
        $this->assertSame($portfolio->id, $log->payload['portfolio_id']);
        $this->assertSame('DOGEUSDT', $log->payload['symbol']);
        $this->assertSame('limit', $log->payload['mode']);
        $this->assertGreaterThanOrEqual(0, $log->payload['decision_ms']);
        $this->assertGreaterThanOrEqual(0, $log->payload['send_ms']);
        $this->assertEqualsWithDelta(100.15, $log->payload['price_read'], 1e-9);
        $this->assertEqualsWithDelta((float) $buy->price, $log->payload['limit_price'], 1e-9);
        $this->assertEqualsWithDelta(100.15, $log->payload['price_answered'], 1e-9);
        $this->assertNull($log->payload['paid']);
    }

    public function test_a_pair_whose_fresh_reading_no_longer_asks_for_entry_is_not_bought()
    {
        $this->candles();
        $portfolio = $this->micro();
        $entry = new MicroSetup(price: 100.15, atrPct: 0.1, uptrend: true, support: 100.0, resistance: 101.0, distanceToSupportPct: 0.15, roomToResistancePct: 0.85, entry: true, reachPct: 1, reasons: []);
        $gone = new MicroSetup(price: 100.4, atrPct: 0.1, uptrend: true, support: 100.0, resistance: 101.0, distanceToSupportPct: 0.4, roomToResistancePct: 0.6, entry: false, reachPct: 1, reasons: ['Longe do suporte.']);

        $this->mock(MicroRanker::class, function ($mock) use ($entry, $gone) {
            $mock->shouldReceive('rank')->andReturn(collect([['asset' => $this->asset, 'setup' => $entry]]));
            $mock->shouldReceive('reread')->once()->andReturn($gone);
        });

        $trader = app(MicroTrader::class);

        $this->assertSame(0, $trader->run($portfolio));
        $this->assertSame(0, Order::count());
        $this->assertStringContainsString('releitura', (string) $trader->lastBlock($portfolio));
    }

    public function test_the_buy_goes_out_on_the_fresh_reading()
    {
        $this->candles();
        $portfolio = $this->micro();
        $stale = new MicroSetup(price: 99.0, atrPct: 0.1, uptrend: true, support: 98.9, resistance: 101.0, distanceToSupportPct: 0.1, roomToResistancePct: 2, entry: true, reachPct: 1, reasons: []);

        $this->mock(MicroRanker::class, function ($mock) use ($stale) {
            $mock->shouldReceive('rank')->andReturn(collect([['asset' => $this->asset, 'setup' => $stale]]));
            $mock->shouldReceive('reread')->once()->andReturnUsing(fn (Portfolio $p, Asset $a, float $gross) => (new MicroRanker(app(MicroRules::class)))->reread($p, $a, $gross));
        });

        $this->assertSame(1, app(MicroTrader::class)->run($portfolio));

        // The stale reading said 99.0; the order and the log carry the chart of now.
        $this->assertEqualsWithDelta(100.15, AuditLog::where('action', 'micro.buy_latency')->sole()->payload['price_read'], 1e-9);
        $this->assertGreaterThan(99.5, (float) Order::sole()->price);
    }

    public function test_the_latency_command_summarises_the_buys()
    {
        $this->candles();
        $portfolio = $this->micro();
        app(MicroTrader::class)->run($portfolio);

        $this->artisan('micro:latency', ['--portfolio' => $portfolio->id])
            ->expectsOutputToContain('1 compra(s) micro medidas nas últimas 24 h.')
            ->expectsOutputToContain('Leitura do gráfico até o envio: mediana')
            ->expectsOutputToContain('Compras limitadas executadas (total ou parcial): 0 de 1.')
            ->assertSuccessful();

        $this->artisan('micro:latency', ['--portfolio' => $portfolio->id + 1])
            ->expectsOutputToContain('Nenhuma compra micro medida')
            ->assertSuccessful();
    }
}
