<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Reports\AgentScoreboard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:score')]
#[Description('Recalcula a taxa de acerto e o peso de cada agente no consenso')]
class ScoreAgents extends Command
{
    public function handle(AgentScoreboard $scoreboard): int
    {
        foreach (Market::cases() as $market) {
            $scoreboard->refresh($market);
        }

        $this->info('Placar dos agentes atualizado.');

        return self::SUCCESS;
    }
}
