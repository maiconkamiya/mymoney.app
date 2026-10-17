<?php

namespace Tests\Concerns;

use App\Forecast\ForecastClient;
use App\Models\Asset;
use App\Models\NewsItem;

trait ProvidesAgentInputs
{
    /**
     * Give the quant and news agents something to judge, so they vote instead of abstaining.
     * (Fundamentals only exist for B3 assets; on crypto that agent always abstains.)
     */
    protected function provideAgentInputs(Asset $asset): void
    {
        $this->app->instance(ForecastClient::class, new class extends ForecastClient
        {
            public function predict(string $symbol, string $market, array $candles, array $news): ?array
            {
                return ['direction' => 'up', 'probability_up' => 0.7, 'probability_down' => 0.3, 'confidence' => 0.4, 'horizon_candles' => 15, 'model_version' => 'teste'];
            }
        });

        $item = NewsItem::create([
            'source' => 'rss', 'url' => 'https://example.com/n', 'url_hash' => NewsItem::hashUrl('https://example.com/n'),
            'title' => 'Notícia de teste', 'fetched_at' => now(),
        ]);
        $item->assets()->attach($asset->id, ['sentiment' => 0.5, 'relevance' => 0.8, 'is_new_fact' => true]);
    }
}
