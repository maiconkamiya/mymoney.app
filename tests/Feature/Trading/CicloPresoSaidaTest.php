<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\Candle;
use App\Models\MicroCycle;
use App\Models\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration of #208 books the cycles that left the account with no exit price
 * (the QKC of the real portfolio 2) at the 1 minute close of when they closed.
 */
class CicloPresoSaidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_migracao_preenche_a_saida_dos_ciclos_que_sairam_da_conta_sem_preco()
    {
        $asset = Asset::factory()->create(['symbol' => 'QKCUSDT']);
        $portfolio = Portfolio::factory()->create();
        $closedAt = Carbon::parse('2026-10-20 11:26:30');
        $cycle = fn (array $attributes) => MicroCycle::create($attributes + [
            'portfolio_id' => $portfolio->id,
            'asset_id' => $asset->id,
            'status' => 'closed',
            'qty' => 2000,
            'entry_price' => 0.002684,
            'target_price' => 0.00271,
            'stop_price' => 0.00265,
            'fees' => 0.0054,
            'gross_margin_pct' => 1,
            'exit_reason' => 'conta',
            'opened_at' => '2026-10-18 18:54:00',
            'closed_at' => $closedAt,
        ]);
        $stuck = $cycle([]);
        // The 17/10 ones were closed by mistake while the coins were still there: they stay as they are.
        $old = $cycle(['opened_at' => '2026-10-17 21:00:00', 'closed_at' => '2026-10-17 22:00:00']);
        $sold = $cycle(['exit_reason' => 'target', 'exit_price' => 0.00271, 'net_pnl' => 0.04]);

        foreach (['2026-10-20 11:25:00' => 0.002284, '2026-10-20 11:26:00' => 0.002290, '2026-10-20 11:27:00' => 0.0025] as $openAt => $close) {
            Candle::create(['asset_id' => $asset->id, 'timeframe' => '1m', 'open_at' => $openAt, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1]);
        }

        Schema::table('micro_cycles', fn ($table) => $table->dropColumn('exit_source'));
        (require database_path('migrations/2026_10_20_000000_add_exit_source_to_micro_cycles.php'))->up();

        $stuck->refresh();
        $this->assertSame('cotacao', $stuck->exit_source);
        $this->assertEqualsWithDelta(0.00229, $stuck->exit_price, 1e-9);
        $fees = 0.0054 + 2000 * 0.00229 * 0.001;
        $this->assertEqualsWithDelta($fees, $stuck->fees, 1e-8);
        $this->assertEqualsWithDelta(2000 * (0.00229 - 0.002684) - $fees, $stuck->net_pnl, 1e-8);

        $this->assertNull($old->fresh()->exit_price);
        $this->assertNull($old->fresh()->net_pnl);
        $this->assertSame('venda', $sold->fresh()->exit_source);
        $this->assertEqualsWithDelta(0.04, $sold->fresh()->net_pnl, 1e-8);
    }
}
