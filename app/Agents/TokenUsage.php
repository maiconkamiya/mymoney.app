<?php

namespace App\Agents;

use App\Models\AgentVote;
use App\Models\Portfolio;
use App\Models\Signal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Tokens que os agentes gastaram nos sinais da carteira: hoje, na semana, no mês
 * e a projeção do mês pela média diária. Cada voto grava os tokens da sua chamada;
 * voto reaproveitado (Notícias) ou abstenção sem chamada conta zero.
 * O explorador e a triagem de notícias são globais e ficam fora.
 */
class TokenUsage
{
    /**
     * @return array{
     *     since: string|null,
     *     today: array{input: int, output: int, total: int, cost: float, calls: int},
     *     week: array{input: int, output: int, total: int, cost: float, calls: int},
     *     month: array{input: int, output: int, total: int, cost: float, calls: int},
     *     estimate: array{input: int, output: int, total: int, cost: float, days: int, days_in_month: int},
     * }
     */
    public function for(Portfolio $portfolio): array
    {
        $now = now();
        $first = $this->votes($portfolio)->where('input_tokens', '>', 0)->min('created_at');
        $since = $first === null ? null : Date::parse($first);
        $month = $this->sum($portfolio, $now->copy()->startOfMonth());

        // Dias com contagem neste mês, hoje incluído: começa no 1º dia ou no primeiro voto, o que vier depois.
        $start = $now->copy()->startOfMonth();

        if ($since !== null && $since->greaterThan($start)) {
            $start = $since->copy()->startOfDay();
        }

        $days = (int) $start->diffInDays($now->copy()->startOfDay()) + 1;
        $remaining = (int) $now->copy()->startOfDay()->diffInDays($now->copy()->endOfMonth()->startOfDay());
        $project = fn (int|float $value) => $value + $value / $days * $remaining;

        return [
            'since' => $since?->toIso8601String(),
            'today' => $this->sum($portfolio, $now->copy()->startOfDay()),
            'week' => $this->sum($portfolio, $now->copy()->startOfWeek(CarbonInterface::MONDAY)),
            'month' => $month,
            'estimate' => [
                'input' => (int) round($project($month['input'])),
                'output' => (int) round($project($month['output'])),
                'total' => (int) round($project($month['total'])),
                'cost' => round($project($month['cost']), 4),
                'days' => $days,
                'days_in_month' => $now->daysInMonth,
            ],
        ];
    }

    /**
     * @return array{input: int, output: int, total: int, cost: float, calls: int}
     */
    private function sum(Portfolio $portfolio, CarbonInterface $from): array
    {
        $row = $this->votes($portfolio)
            ->where('created_at', '>=', $from)
            ->selectRaw('coalesce(sum(input_tokens), 0) as input, coalesce(sum(output_tokens), 0) as output, coalesce(sum(cost), 0) as cost, coalesce(sum(case when input_tokens > 0 then 1 else 0 end), 0) as calls')
            ->toBase()
            ->first();

        $input = (int) ($row->input ?? 0);
        $output = (int) ($row->output ?? 0);

        return [
            'input' => $input,
            'output' => $output,
            'total' => $input + $output,
            'cost' => round((float) ($row->cost ?? 0), 4),
            'calls' => (int) ($row->calls ?? 0),
        ];
    }

    /**
     * @return Builder<AgentVote>
     */
    private function votes(Portfolio $portfolio): Builder
    {
        return AgentVote::query()->whereIn('signal_id', Signal::query()->select('id')->where('portfolio_id', $portfolio->id));
    }
}
