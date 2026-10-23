<?php

namespace App\Trading\Micro;

/**
 * The week behind a pair (#220): how many 1-minute candles it has, the mean of
 * their closes, the highest high and the average 1-minute range in percent.
 */
final readonly class MicroWeek
{
    public function __construct(
        public int $candles,
        public float $averageClose,
        public float $high,
        public float $rangePct,
    ) {}

    /**
     * From 1-minute candles, oldest first; only the last `trading.micro.week.minutes` are read.
     *
     * @param  list<array{high: float, low: float, close: float}>  $candles
     */
    public static function fromCandles(array $candles): self
    {
        $count = 0;
        $closes = 0.0;
        $high = 0.0;
        $range = 0.0;

        foreach (array_slice($candles, -(int) config('trading.micro.week.minutes')) as $candle) {
            $close = (float) $candle['close'];

            if ($close <= 0) {
                continue;
            }

            $count++;
            $closes += $close;
            $high = max($high, (float) $candle['high']);
            $range += ((float) $candle['high'] - (float) $candle['low']) / $close * 100;
        }

        return $count === 0 ? new self(0, 0.0, 0.0, 0.0) : new self($count, $closes / $count, $high, $range / $count);
    }
}
