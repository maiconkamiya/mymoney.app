<?php

use App\Jobs\FetchNewsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:sync-binance')->everyMinute()->withoutOverlapping();
Schedule::command('signals:scan')->everyFifteenMinutes()->withoutOverlapping();

foreach (array_filter(array_keys(config('news.sources')), is_string(...)) as $source) {
    Schedule::job(new FetchNewsJob($source))->everyFiveMinutes();
}

Schedule::command('portfolios:snapshot')->dailyAt('23:55')->withoutOverlapping();
Schedule::command('agents:score')->dailyAt('00:10')->withoutOverlapping();
