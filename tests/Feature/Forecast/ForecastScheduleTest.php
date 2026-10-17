<?php

namespace Tests\Feature\Forecast;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ForecastScheduleTest extends TestCase
{
    private function trainEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'forecast:train'));

        $this->assertNotNull($event, 'forecast:train não está agendado');

        return $event;
    }

    public function test_weekly_training_is_scheduled_on_sunday_at_four()
    {
        $this->assertSame('0 4 * * 0', $this->trainEvent()->expression);
    }

    public function test_weekly_training_only_runs_when_the_forecast_is_enabled()
    {
        config(['forecast.enabled' => false]);
        $this->assertFalse($this->trainEvent()->filtersPass($this->app));

        config(['forecast.enabled' => true]);
        $this->assertTrue($this->trainEvent()->filtersPass($this->app));
    }
}
