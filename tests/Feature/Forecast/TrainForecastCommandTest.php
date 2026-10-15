<?php

namespace Tests\Feature\Forecast;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMarket;
use Tests\TestCase;

class TrainForecastCommandTest extends TestCase
{
    use BuildsMarket, RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('forecast');
        parent::tearDown();
    }

    public function test_exports_candles_and_runs_the_python_trainer()
    {
        Process::fake(['*' => Process::result('{"validated": true}')]);
        $asset = Asset::factory()->create(['symbol' => 'BTCUSDT']);
        $this->quote($asset, 100, now()->subMinutes(5));
        $this->quote($asset, 101, now()->subMinute());

        $this->artisan('forecast:train', ['--horizon' => 10])->assertSuccessful();

        $csv = file(Storage::disk('local')->path('forecast/candles.csv'), FILE_IGNORE_NEW_LINES);
        $this->assertCount(3, $csv);
        $this->assertStringStartsWith('BTCUSDT,1m,', $csv[1]);
        Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), '-m forecast.train')
            && str_contains(implode(' ', (array) $process->command), '--horizon 10'));
    }

    public function test_reports_failure_from_the_trainer()
    {
        Process::fake(['*' => Process::result(errorOutput: 'sem dados', exitCode: 1)]);

        $this->artisan('forecast:train')->assertFailed();
    }
}
