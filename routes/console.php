<?php

use App\Jobs\FetchNewsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:sync-binance')->everyMinute()->withoutOverlapping();
Schedule::command('market:sync-b3')->everyMinute()->weekdays()->withoutOverlapping();
Schedule::command('market:sync-b3 --fundamentals')->dailyAt('07:00')->weekdays();
Schedule::command('positions:check-exits')->everyMinute()->withoutOverlapping();
Schedule::command('micro:run')->everyMinute()->withoutOverlapping();
Schedule::command('signals:scan')->cron('*/'.config('trading.scan_interval_minutes').' * * * *')->withoutOverlapping();
Schedule::command('binance:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('trading:reconcile')->everyFiveMinutes()->withoutOverlapping();

foreach (array_filter(array_keys(config('news.sources')), is_string(...)) as $source) {
    Schedule::job(new FetchNewsJob($source))->everyFiveMinutes();
}

Schedule::command('portfolios:snapshot')->dailyAt('23:55')->withoutOverlapping();
Schedule::command('agents:score')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('forecast:train')->weeklyOn(0, '04:00')->withoutOverlapping()->runInBackground()->when(fn () => (bool) config('forecast.enabled'));
