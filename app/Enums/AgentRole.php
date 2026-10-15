<?php

namespace App\Enums;

enum AgentRole: string
{
    case Coordinator = 'coordinator';
    case Technical = 'technical';
    case News = 'news';
    case Fundamentals = 'fundamentals';
    case Quant = 'quant';
    case Skeptic = 'skeptic';
    case Risk = 'risk';
    case Executor = 'executor';

    /**
     * Roles that cast a vote in the consensus round.
     *
     * @return list<self>
     */
    public static function voters(): array
    {
        return [self::Technical, self::News, self::Fundamentals, self::Quant, self::Skeptic, self::Risk];
    }

    public function canVeto(): bool
    {
        return $this === self::Risk;
    }
}
