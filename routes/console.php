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
Schedule::command('signals:scan')->everyFifteenMinutes()->withoutOverlapping();

foreach (array_filter(array_keys(config('news.sources')), is_string(...)) as $source) {
    Schedule::job(new FetchNewsJob($source))->everyFiveMinutes();
}
