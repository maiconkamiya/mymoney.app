<?php

namespace App\Jobs;

use App\Agents\SignalPipeline;
use App\Models\Signal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeSignalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $signalId) {}

    public function handle(SignalPipeline $pipeline): void
    {
        $signal = Signal::find($this->signalId);

        if ($signal !== null) {
            $pipeline->analyze($signal);
        }
    }
}
