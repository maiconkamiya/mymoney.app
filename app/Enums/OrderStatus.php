<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Filled = 'filled';
    case PartiallyFilled = 'partially_filled';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
}
