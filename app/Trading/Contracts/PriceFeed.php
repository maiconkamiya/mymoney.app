<?php

namespace App\Trading\Contracts;

use App\Models\Asset;
use App\Trading\Data\Quote;

interface PriceFeed
{
    public function quote(Asset $asset): ?Quote;
}
