<?php

namespace App\Reports;

use App\Enums\AgentRole;
use App\Enums\Market;
use App\Enums\SignalStage;
use App\Models\AgentScore;
use App\Models\AgentVote;
use App\Models\Signal;
use App\Trading\Contracts\PriceFeed;

/**
 * Hit rate per agent, written to `agent_scores` and turned into the weight the
 * consensus uses.
 *
 * A signal counts once it was traded. The price move since its entry decides
 * the outcome, and an agent's final directional vote is a hit when it pointed
 * the same way as the move. Hold votes make no call, so they are not scored.
 */
class AgentScoreboard
{
    /** Signals an agent needs before its weight moves away from 1. */
    public const MIN_SAMPLE = 10;

    public function __construct(private readonly PriceFeed $prices) {}

    /**
     * Recompute the score of every voting agent in a market over the last `$window` traded signals.
     *
     * @return list<AgentScore>
     */
    public function refresh(Market $market, int $window = 100): array
    {
        $signals = Signal::query()
            ->whereIn('stage', [SignalStage::Executed, SignalStage::Closed])
            ->whereNotNull('entry_price')
            ->whereHas('asset', fn ($query) => $query->where('market', $market))
            ->with(['asset', 'votes'])
            ->latest()
            ->limit($window)
            ->get();

        /** @var array<string, array{signals: int, hits: int}> $tally */
        $tally = [];

        foreach ($signals as $signal) {
            $direction = $this->moveDirection($signal);

            if ($direction === 0) {
                continue;
            }

            foreach ($this->finalVotes($signal) as $vote) {
                $call = $vote->action->direction();

                if ($call === 0) {
                    continue;
                }

                $tally[$vote->agent->value] ??= ['signals' => 0, 'hits' => 0];
                $tally[$vote->agent->value]['signals']++;
                $tally[$vote->agent->value]['hits'] += $call === $direction ? 1 : 0;
            }
        }

        $scores = [];

        foreach (AgentRole::voters() as $role) {
            $count = $tally[$role->value]['signals'] ?? 0;
            $hits = $tally[$role->value]['hits'] ?? 0;
            $rate = $count > 0 ? $hits / $count : 0.0;

            $scores[] = AgentScore::updateOrCreate(
                ['agent' => $role, 'market' => $market],
                ['window' => $window, 'signals' => $count, 'hits' => $hits, 'hit_rate' => round($rate, 4), 'weight' => self::weight($count, $rate)],
            );
        }

        return $scores;
    }

    /**
     * Consensus weight: 1 until there is enough history, then 0.5 to 1.5 following the hit rate.
     */
    public static function weight(int $signals, float $hitRate): float
    {
        if ($signals < self::MIN_SAMPLE) {
            return 1.0;
        }

        return round(0.5 + min(max($hitRate, 0.0), 1.0), 4);
    }

    /**
     * +1 when the price rose since the signal's entry, -1 when it fell, 0 without a quote or change.
     */
    private function moveDirection(Signal $signal): int
    {
        $quote = $this->prices->quote($signal->asset);

        return $quote === null || $signal->entry_price === null ? 0 : $quote->price <=> $signal->entry_price;
    }

    /**
     * Each agent's vote from the last round it took part in.
     *
     * @return list<AgentVote>
     */
    private function finalVotes(Signal $signal): array
    {
        return $signal->votes->sortByDesc('round')->unique(fn (AgentVote $vote) => $vote->agent->value)->values()->all();
    }
}
