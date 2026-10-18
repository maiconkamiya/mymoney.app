<?php

use App\Jobs\FetchNewsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:sync-binance')->everyMinute()->withoutOverlapping();
Schedule::command('market:sync-b3')->everyMinute()->weekdays()->withoutOverlapping()->when(fn () => (bool) config('trading.b3_sync'));
Schedule::command('market:sync-b3 --fundamentals')->dailyAt('07:00')->weekdays()->when(fn () => (bool) config('trading.b3_sync'));
Schedule::command('positions:check-exits')->everyMinute()->withoutOverlapping();
Schedule::command('micro:run')->everyMinute()->withoutOverlapping();
Schedule::command('micro:backtest --save')->weeklyOn(0, '05:00')->withoutOverlapping()->runInBackground();
$scanMinutes = (int) config('trading.scan_interval_minutes');
Schedule::command('signals:scan')->cron($scanMinutes >= 60 ? '0 * * * *' : '*/'.$scanMinutes.' * * * *')->withoutOverlapping();
Schedule::command('binance:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('trading:reconcile')->everyFiveMinutes()->withoutOverlapping();

$newsSources = array_filter(config('news.sources'), fn (array $source) => $source['market'] !== 'b3' || config('news.b3_sources'));

foreach (array_filter(array_keys($newsSources), is_string(...)) as $source) {
    Schedule::job(new FetchNewsJob($source))->everyFiveMinutes();
}

Schedule::command('portfolios:snapshot')->dailyAt('23:55')->withoutOverlapping();
Schedule::command('agents:score')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('forecast:train')->weeklyOn(0, '04:00')->withoutOverlapping()->runInBackground()->when(fn () => (bool) config('forecast.enabled'));
Schedule::command('insights:write')->dailyAt((string) config('insights.ai.daily_at'))->withoutOverlapping()->when(fn () => (bool) config('insights.ai.enabled'));
Schedule::command('explorer:scan')->cron('*/'.config('explorer.interval_minutes').' * * * *')->withoutOverlapping()->when(fn () => (bool) config('explorer.enabled'));
