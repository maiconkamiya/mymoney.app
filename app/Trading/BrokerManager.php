<?php

namespace App\Trading;

use App\Models\Portfolio;
use App\Trading\Contracts\Broker;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class BrokerManager
{
    public function __construct(private readonly Container $container) {}

    public function for(Portfolio $portfolio): Broker
    {
        $class = config('trading.brokers.'.$portfolio->broker);

        if ($class === null) {
            throw new InvalidArgumentException("Broker [{$portfolio->broker}] não configurado.");
        }

        if (! $portfolio->isLive() && $portfolio->broker !== 'paper') {
            throw new InvalidArgumentException('Carteiras simuladas só podem usar o PaperBroker.');
        }

        /** @var Broker $broker */
        $broker = $this->container->make($class, ['portfolio' => $portfolio]);

        if (! $broker->supports($portfolio->market)) {
            throw new InvalidArgumentException("Broker [{$portfolio->broker}] não opera {$portfolio->market->value}.");
        }

        return $broker;
    }
}
