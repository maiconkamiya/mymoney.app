<?php

namespace App\Enums;

enum PortfolioMode: string
{
    case Backtest = 'backtest';
    case Paper = 'paper';
    case Live = 'live';
}
