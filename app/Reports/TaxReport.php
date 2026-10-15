<?php

namespace App\Reports;

use App\Enums\OrderSide;
use App\Models\Fill;
use App\Models\Portfolio;

/**
 * Operations and monthly result for income-tax purposes. Realised result is
 * computed by replaying fills in order with a running average cost per asset;
 * buy fees go into the cost, sell fees are deducted from the proceeds.
 */
class TaxReport
{
    /**
     * @return list<array{date: string, month: string, symbol: string, side: string, qty: float, price: float, value: float, fee: float, result: float}>
     */
    public function operations(Portfolio $portfolio, ?int $year = null): array
    {
        $fills = Fill::query()
            ->whereHas('order', fn ($query) => $query->where('portfolio_id', $portfolio->id))
            ->with('order.asset')
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();

        /** @var array<int, array{qty: float, cost: float}> $books */
        $books = [];
        $rows = [];

        foreach ($fills as $fill) {
            $assetId = $fill->order->asset_id;
            $book = $books[$assetId] ?? ['qty' => 0.0, 'cost' => 0.0];
            $value = $fill->qty * $fill->price;
            $result = 0.0;

            if ($fill->order->side === OrderSide::Buy) {
                $book['qty'] += $fill->qty;
                $book['cost'] += $value + $fill->fee;
            } else {
                $average = $book['qty'] > 0 ? $book['cost'] / $book['qty'] : $fill->price;
                $result = $value - $fill->fee - $average * $fill->qty;
                $book['cost'] -= $average * $fill->qty;
                $book['qty'] = max($book['qty'] - $fill->qty, 0.0);
            }

            $books[$assetId] = $book;

            if ($year === null || $fill->executed_at->year === $year) {
                $rows[] = [
                    'date' => $fill->executed_at->toDateString(),
                    'month' => $fill->executed_at->format('Y-m'),
                    'symbol' => $fill->order->asset->symbol,
                    'side' => $fill->order->side->value,
                    'qty' => $fill->qty,
                    'price' => $fill->price,
                    'value' => round($value, 2),
                    'fee' => round($fill->fee, 2),
                    'result' => round($result, 2),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{month: string, bought: float, sold: float, fees: float, result: float}>
     */
    public function months(Portfolio $portfolio, ?int $year = null): array
    {
        $months = [];

        foreach ($this->operations($portfolio, $year) as $row) {
            $month = $months[$row['month']] ??= ['month' => $row['month'], 'bought' => 0.0, 'sold' => 0.0, 'fees' => 0.0, 'result' => 0.0];
            $month[$row['side'] === 'buy' ? 'bought' : 'sold'] += $row['value'];
            $month['fees'] += $row['fee'];
            $month['result'] += $row['result'];
            $months[$row['month']] = $month;
        }

        ksort($months);

        return array_values(array_map(fn (array $m) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $m), $months));
    }
}
