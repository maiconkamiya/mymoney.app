<?php

namespace App\Agents;

use App\Enums\PortfolioStatus;
use App\Enums\SignalStage;
use App\Jobs\AnalyzeSignalJob;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Signal;

/**
 * Opens a "scanned" signal for every running portfolio that trades the asset,
 * unless one is already in flight.
 */
class SignalScanner
{
    /**
     * @return list<Signal>
     */
    public function scan(Asset $asset): array
    {
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
}
