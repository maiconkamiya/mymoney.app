<?php

namespace App\Agents;

use App\Enums\PortfolioStatus;
use App\Enums\SignalStage;
use App\Jobs\AnalyzeSignalJob;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Trading\Contracts\PriceFeed;
use App\Trading\MarketHours;

/**
 * Opens a "scanned" signal for every running portfolio that trades the asset,
 * unless one is already in flight. Assets that cannot be traded right now
 * (market closed, or no fresh quote) are skipped so no agent call is spent
 * on an order the broker or the risk guard would refuse anyway.
 */
class SignalScanner
{
    public function __construct(
        private readonly MarketHours $hours,
        private readonly PriceFeed $prices,
    ) {}

    /**
     * @return list<Signal>
     */
    public function scan(Asset $asset): array
    {
        Signal::query()
            ->where('asset_id', $asset->id)
            ->where('stage', SignalStage::Approved)
            ->get()
            ->each(fn (Signal $signal) => $signal->isExpired() && $signal->expire());

        if (! $this->tradable($asset)) {
            return [];
        }

        $signals = [];

        $portfolios = Portfolio::query()
            ->where('status', PortfolioStatus::Running)
            ->where('market', $asset->market)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->get();

        foreach ($portfolios as $portfolio) {
            $inFlight = $portfolio->signals()
                ->where('asset_id', $asset->id)
                ->whereIn('stage', [SignalStage::Scanned, SignalStage::Analyzed, SignalStage::Approved, SignalStage::Confirmed])
                ->exists();

            if ($inFlight) {
                continue;
            }

            $signal = $portfolio->signals()->create([
                'asset_id' => $asset->id,
                'stage' => SignalStage::Scanned,
                'stage_history' => [['stage' => SignalStage::Scanned->value, 'at' => now()->toIso8601String()]],
            ]);

            AnalyzeSignalJob::dispatch($signal->id);
            $signals[] = $signal;
        }

        return $signals;
    }

    private function tradable(Asset $asset): bool
    {
        if (! $this->hours->isOpen($asset->market)) {
            return false;
        }

        $quote = $this->prices->quote($asset);

        return $quote !== null && $quote->ageInSeconds() <= config('trading.max_price_age_seconds.'.$asset->market->value);
    }
}
