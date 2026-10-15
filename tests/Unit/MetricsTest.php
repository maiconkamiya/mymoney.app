<?php

namespace Tests\Unit;

use App\Reports\Metrics;
use PHPUnit\Framework\TestCase;

class MetricsTest extends TestCase
{
    public function test_total_return_and_drawdown()
    {
        $equity = [100.0, 120.0, 90.0, 110.0];

        $this->assertEqualsWithDelta(0.10, Metrics::totalReturn($equity), 1e-9);
        $this->assertEqualsWithDelta(0.25, Metrics::maxDrawdown($equity), 1e-9);
    }

    public function test_sharpe_is_annualised_and_null_without_variance()
    {
        $equity = [100.0, 101.0, 100.0, 102.0, 103.0];
        $returns = Metrics::returns($equity);
        $mean = array_sum($returns) / count($returns);
        $std = sqrt(array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $returns)) / (count($returns) - 1));

        $this->assertEqualsWithDelta($mean / $std * sqrt(252), Metrics::sharpe($equity, 252), 1e-9);
        $this->assertNull(Metrics::sharpe([100.0, 100.0, 100.0], 252));
        $this->assertNull(Metrics::sharpe([100.0], 252));
    }

    public function test_empty_series_is_safe()
    {
        $this->assertSame(0.0, Metrics::totalReturn([]));
        $this->assertSame(0.0, Metrics::maxDrawdown([]));
    }
}
