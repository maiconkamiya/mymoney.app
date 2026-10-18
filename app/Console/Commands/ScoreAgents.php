<?php

namespace App\Console\Commands;

use App\Enums\Market;
use App\Events\PortfolioChanged;
use App\Reports\AgentScoreboard;
use App\Trading\PortfolioChangeBroadcaster;
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
            PortfolioChangeBroadcaster::notifyMarket($market, [PortfolioChanged::SCOPE_AGENTS]);
        }

        $this->info('Placar dos agentes atualizado.');

        return self::SUCCESS;
    }
}
