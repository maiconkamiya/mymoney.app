<?php

namespace Tests\Feature\MarketData;

use App\Events\CotacaoAtualizada;
use App\MarketData\TradeStream;
use App\Models\Asset;
use App\Models\Candle;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamBinanceTradesTest extends TestCase
{
    use RefreshDatabase;

    public function test_agrega_negocios_em_um_candle_e_transmite_a_cotacao(): void
    {
        Event::fake([CotacaoAtualizada::class]);
        $btc = Asset::factory()->create(['symbol' => 'BTCUSDT', 'market' => 'crypto']);

        $this->app->instance(TradeStream::class, new class implements TradeStream
        {
            public function listen(array $symbols, Closure $onTrade, Closure $onTick): void
            {
                $ms = 1_700_000_010_000;
                $onTrade('BTCUSDT', 100.0, 1.0, $ms);
                $onTrade('BTCUSDT', 110.0, 0.5, $ms + 1000);
                $onTrade('XRPUSDT', 1.0, 1.0, $ms); // fora da lista: ignorado
                $onTick();
                $onTick();
            }
        });

        $this->artisan('market:stream-binance', ['--max-seconds' => 1])->assertSuccessful();

        $candle = Candle::query()->where('asset_id', $btc->id)->sole();
        $this->assertSame(110.0, (float) $candle->close);
        $this->assertSame(1.5, (float) $candle->volume);

        Event::assertDispatchedTimes(CotacaoAtualizada::class, 1);
        Event::assertDispatched(CotacaoAtualizada::class, fn ($e) => $e->symbol === 'BTCUSDT' && $e->price === 110.0 && $e->candle['close'] === 110.0);
    }

    public function test_o_evento_vai_para_o_canal_publico_mercado(): void
    {
        $event = new CotacaoAtualizada('BTCUSDT', 1.0, ['open_at' => 'x', 'open' => 1.0, 'high' => 1.0, 'low' => 1.0, 'close' => 1.0, 'volume' => 0.0]);

        $this->assertSame('mercado', $event->broadcastOn()->name);
        $this->assertSame('cotacao.atualizada', $event->broadcastAs());
    }
}
