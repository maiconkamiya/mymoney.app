<?php

namespace Tests\Feature\Trading;

use App\Enums\OrderSide;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\User;
use App\Trading\Data\OrderRequest;
use App\Trading\Micro\MicroTrader;
use App\Trading\Risk\RiskGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

/**
 * #191 e #192: a perda do dia do card é o limite da guarda de risco da carteira, em % do saldo
 * do início do dia; vazio segue o perfil (2%), com teto de 20%.
 */
class PerdaDoDiaTest extends TestCase
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
     * Carteira de 20 USDT que começou o dia com 20 e está com 19,38 (-3,1%).
     */
    private function losingPortfolio(array $attributes = []): Portfolio
    {
        $portfolio = Portfolio::factory()->create($attributes + [
            'initial_cash' => 20,
            'cash' => 19.38,
            'micro_enabled' => true,
            'micro_order_value' => 5,
            'micro_net_margin_pct' => 0.2,
        ]);
        $portfolio->snapshots()->create(['taken_at' => now()->startOfDay()->addMinute(), 'equity' => 20, 'cash' => 20, 'exposure' => 0]);

        return $portfolio;
    }

    private function buy(): OrderRequest
    {
        return new OrderRequest($this->asset, OrderSide::Buy, 0.01, stopPrice: 95);
    }

    private function save(Portfolio $portfolio, ?string $loss, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $portfolio->user)->put(route('portfolios.micro', $portfolio), [
            'micro_enabled' => '1',
            'micro_order_value' => '5',
            'micro_net_margin_pct' => '0.2',
            'daily_loss_pct' => $loss,
        ]);
    }

    public function test_raising_the_loss_in_the_card_releases_the_guard()
    {
        $portfolio = $this->losingPortfolio();
        $guard = app(RiskGuard::class);

        $this->assertSame(['Perda máxima do dia atingida.'], $guard->check($portfolio, $this->buy(), ownerSized: true)->reasons);
        $this->assertSame('loss_limit', app(MicroTrader::class)->state($portfolio)['code']);
        $this->assertStringContainsString('2% do saldo', app(MicroTrader::class)->state($portfolio)['label']);

        $this->save($portfolio, '5')->assertSessionHasNoErrors();
        $portfolio->refresh();

        $this->assertTrue($guard->check($portfolio, $this->buy(), ownerSized: true)->allowed);
        $this->assertSame('active', app(MicroTrader::class)->state($portfolio)['code']);
    }

    public function test_the_loss_is_capped_and_empty_follows_the_risk_profile()
    {
        $portfolio = $this->losingPortfolio();

        // 1000 (o que se digitaria pensando em USDT) não passa do teto de 20%.
        $this->save($portfolio, '1000')->assertSessionHasErrors('daily_loss_pct');

        $this->save($portfolio, '')->assertSessionHasNoErrors();
        $this->assertNull($portfolio->fresh()->daily_loss_pct);
        $this->assertEqualsWithDelta(0.02, app(RiskGuard::class)->dailyLossLimit($portfolio->fresh()), 0.0001);

        // Mesmo gravado acima do teto, a guarda usa no máximo 20%.
        $portfolio->forceFill(['daily_loss_pct' => 50])->save();
        $this->assertEqualsWithDelta(0.20, app(RiskGuard::class)->dailyLossLimit($portfolio->fresh()), 0.0001);
    }

    public function test_a_lower_limit_blocks_sooner_than_the_profile()
    {
        $portfolio = $this->losingPortfolio(['cash' => 19.9]);

        $this->assertTrue(app(RiskGuard::class)->check($portfolio, $this->buy(), ownerSized: true)->allowed);

        $portfolio->update(['daily_loss_pct' => 0.4]);

        $this->assertFalse(app(RiskGuard::class)->check($portfolio->fresh(), $this->buy(), ownerSized: true)->allowed);
    }

    public function test_the_target_is_a_percent_of_the_start_of_day_balance()
    {
        $portfolio = $this->losingPortfolio(['micro_daily_target_pct' => 2.5]);

        $this->assertEqualsWithDelta(0.5, app(MicroTrader::class)->dailyTargetValue($portfolio), 0.0001);
    }

    public function test_other_users_get_403_and_the_limit_does_not_change()
    {
        $portfolio = $this->losingPortfolio();

        $this->save($portfolio, '20', User::factory()->create())->assertForbidden();
        $this->assertNull($portfolio->fresh()->daily_loss_pct);
    }
}
