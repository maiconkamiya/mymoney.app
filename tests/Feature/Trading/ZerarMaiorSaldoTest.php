<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Enums\PortfolioMode;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Data\OrderRequest;
use App\Trading\Micro\MicroTrader;
use App\Trading\PortfolioValuation;
use App\Trading\Risk\RiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

/**
 * #222: a queda máxima desde o maior saldo é escolhida por carteira no card (vazio segue o perfil)
 * e o dono pode zerar o maior saldo, que passa a contar do patrimônio de agora.
 */
class ZerarMaiorSaldoTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create(['lot_size' => '0.001']);
        $this->quote($this->asset, 100);
    }

    /**
     * Maior saldo 20 ontem, 17,98 desde o início de hoje: queda de 10,1%, acima dos 10% do perfil.
     */
    private function droppedPortfolio(array $attributes = []): Portfolio
    {
        $portfolio = Portfolio::factory()->create($attributes + [
            'initial_cash' => 20,
            'cash' => 17.98,
            'micro_enabled' => true,
            'micro_order_value' => 5,
            'micro_net_margin_pct' => 0.2,
        ]);
        $portfolio->snapshots()->create(['taken_at' => now()->subDay(), 'equity' => 20, 'cash' => 20, 'exposure' => 0]);
        $portfolio->snapshots()->create(['taken_at' => now()->startOfDay()->addMinute(), 'equity' => 17.98, 'cash' => 17.98, 'exposure' => 0]);

        return $portfolio;
    }

    private function buy(): OrderRequest
    {
        return new OrderRequest($this->asset, OrderSide::Buy, 0.01, stopPrice: 95);
    }

    private function save(Portfolio $portfolio, ?string $drawdown, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $portfolio->user)->put(route('portfolios.micro', $portfolio), [
            'micro_enabled' => '1',
            'micro_order_value' => '5',
            'micro_net_margin_pct' => '0.2',
            'max_drawdown_pct' => $drawdown,
        ]);
    }

    public function test_raising_the_drawdown_in_the_card_releases_the_guard()
    {
        $portfolio = $this->droppedPortfolio();
        $guard = app(RiskGuard::class);

        $this->assertSame(['Drawdown máximo atingido.'], $guard->check($portfolio, $this->buy(), ownerSized: true)->reasons);

        $this->save($portfolio, '15')->assertSessionHasNoErrors();
        $portfolio->refresh();

        $this->assertEqualsWithDelta(15, $portfolio->max_drawdown_pct, 0.0001);
        $this->assertTrue($guard->check($portfolio, $this->buy(), ownerSized: true)->allowed);
        $this->assertSame('active', app(MicroTrader::class)->state($portfolio)['code']);
        $this->assertSame('15', (string) AuditLog::where('action', 'portfolio.micro_settings')->sole()->payload['max_drawdown_pct']);
    }

    public function test_the_drawdown_is_capped_and_empty_follows_the_profile()
    {
        $portfolio = $this->droppedPortfolio();

        $this->save($portfolio, '80')->assertSessionHasErrors('max_drawdown_pct');
        $this->save($portfolio, '')->assertSessionHasNoErrors();
        $this->assertNull($portfolio->fresh()->max_drawdown_pct);
        $this->assertEqualsWithDelta(0.10, app(RiskGuard::class)->drawdownLimit($portfolio->fresh()), 0.0001);

        // Mesmo gravado acima do teto, a guarda usa no máximo 50%.
        $portfolio->forceFill(['max_drawdown_pct' => 90])->save();
        $this->assertEqualsWithDelta(0.50, app(RiskGuard::class)->drawdownLimit($portfolio->fresh()), 0.0001);
    }

    public function test_resetting_the_peak_releases_the_guard_and_is_audited()
    {
        $portfolio = $this->droppedPortfolio();

        $this->actingAs($portfolio->user)->post(route('portfolios.reset-peak', $portfolio))->assertRedirect();
        $portfolio->refresh();

        $this->assertNotNull($portfolio->peak_reset_at);
        $this->assertEqualsWithDelta(17.98, app(PortfolioValuation::class)->peakEquity($portfolio), 0.0001);
        $this->assertTrue(app(RiskGuard::class)->check($portfolio, $this->buy(), ownerSized: true)->allowed);
        $this->assertSame('active', app(MicroTrader::class)->state($portfolio)['code']);

        $log = AuditLog::where('action', 'portfolio.peak_reset')->sole();
        $this->assertSame('user:'.$portfolio->user_id, $log->actor);
        $this->assertEqualsWithDelta(20, $log->payload['previous_peak'], 0.0001);
        $this->assertTrue($log->payload['was_blocked']);

        // A partir do reset, uma nova queda de 10% volta a barrar as compras.
        $portfolio->update(['cash' => 16.1]);
        $this->assertFalse(app(RiskGuard::class)->check($portfolio->fresh(), $this->buy(), ownerSized: true)->allowed);
    }

    public function test_after_the_reset_a_new_high_becomes_the_peak()
    {
        $portfolio = $this->droppedPortfolio();
        $this->actingAs($portfolio->user)->post(route('portfolios.reset-peak', $portfolio));
        $portfolio->refresh();

        $portfolio->snapshots()->create(['taken_at' => now()->addMinute(), 'equity' => 19, 'cash' => 19, 'exposure' => 0]);

        $this->assertEqualsWithDelta(19, app(PortfolioValuation::class)->peakEquity($portfolio), 0.0001);
    }

    public function test_the_reset_works_on_a_live_portfolio()
    {
        $portfolio = $this->droppedPortfolio(['mode' => PortfolioMode::Live]);
        $valuation = app(PortfolioValuation::class);
        $this->assertEqualsWithDelta(20, $valuation->peakEquity($portfolio), 0.0001);

        $this->actingAs($portfolio->user)->post(route('portfolios.reset-peak', $portfolio));

        $this->assertEqualsWithDelta($valuation->equity($portfolio->fresh()), $valuation->peakEquity($portfolio->fresh()), 0.0001);
    }

    public function test_the_card_shows_the_limit_and_the_reset()
    {
        $portfolio = $this->droppedPortfolio(['max_drawdown_pct' => 12]);
        $this->actingAs($portfolio->user)->post(route('portfolios.reset-peak', $portfolio));

        $this->actingAs($portfolio->user)->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('micro.drawdown.max_drawdown_pct', 12)
            ->where('micro.drawdown.limit_pct', 12)
            ->where('micro.drawdown.default_pct', 10)
            ->where('micro.drawdown.max_pct', RiskGuard::MAX_DRAWDOWN_PCT)
            ->where('micro.drawdown.peak', 17.98)
            ->whereNot('micro.drawdown.reset_at', null));
    }

    public function test_other_users_get_403_and_nothing_changes()
    {
        $portfolio = $this->droppedPortfolio();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('portfolios.reset-peak', $portfolio))->assertForbidden();
        $this->save($portfolio, '40', $intruder)->assertForbidden();

        $portfolio->refresh();
        $this->assertNull($portfolio->peak_reset_at);
        $this->assertNull($portfolio->max_drawdown_pct);
        $this->assertSame(0, AuditLog::where('action', 'portfolio.peak_reset')->count());
    }
}
