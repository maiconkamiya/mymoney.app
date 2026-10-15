<?php

namespace App\Enums;

/**
 * The funnel every signal moves through, as described in the spec.
 */
enum SignalStage: string
{
    case Scanned = 'scanned';
    case Analyzed = 'analyzed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Confirmed = 'confirmed';
    case Executed = 'executed';
    case Closed = 'closed';
}
