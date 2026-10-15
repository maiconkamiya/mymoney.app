<?php

namespace App\Enums;

enum PortfolioStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Paused = 'paused';
    case Finished = 'finished';
}
