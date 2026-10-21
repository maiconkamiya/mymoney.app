<?php

namespace App\Http\Controllers;

use App\Enums\PortfolioMode;
use App\Enums\PortfolioStatus;
use App\Http\Requests\ClonePortfolioRequest;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Trading\PortfolioValuation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PortfolioCloneController extends Controller
{
    /** Período da simulada quando o da origem já está no fim. */
    public const DEFAULT_DAYS = 30;

    /**
     * Cria uma carteira simulada com os ativos acompanhados e o card Micro operações da
     * origem, começando com o patrimônio atual dela em caixa. Posições, ordens, sinais,
     * histórico, credencial da corretora e o estado da execução real ficam para trás:
     * a simulada só usa o PaperBroker e nasce com os sinais esperando confirmação.
     */
    public function __invoke(ClonePortfolioRequest $request, Portfolio $portfolio, PortfolioValuation $valuation): RedirectResponse
    {
        $equity = round($valuation->equity($portfolio), 2);

        if ($equity <= 0) {
            return back()->withErrors(['clone' => 'A carteira não tem patrimônio para levar à simulada.']);
        }

        $clone = DB::transaction(function () use ($request, $portfolio, $equity): Portfolio {
            $endsAt = $portfolio->ends_at->isAfter(now()->addDay()) ? $portfolio->ends_at : now()->addDays(self::DEFAULT_DAYS);

            $clone = $request->user()->portfolios()->create([
                'risk_profile_id' => $portfolio->risk_profile_id,
                'name' => self::cloneName($portfolio),
                'market' => $portfolio->market,
                'mode' => PortfolioMode::Paper,
                'broker' => 'paper',
                'status' => PortfolioStatus::Running,
                'initial_cash' => $equity,
                'cash' => $equity,
                'benchmark' => $portfolio->benchmark,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'auto_execute' => false,
                'micro_enabled' => true,
                'micro_order_value' => $portfolio->micro_order_value,
                'micro_net_margin_pct' => $portfolio->micro_net_margin_pct,
                'micro_daily_target_pct' => $portfolio->micro_daily_target_pct,
                'daily_loss_pct' => $portfolio->daily_loss_pct,
                'micro_daily_operations' => $portfolio->micro_daily_operations,
                'micro_wallet_pct' => $portfolio->micro_wallet_pct,
                'micro_rule' => $portfolio->micro_rule,
                'micro_entry_mode' => $portfolio->micro_entry_mode,
                'micro_limit_seconds' => $portfolio->micro_limit_seconds,
                'micro_exchange_stop' => $portfolio->micro_exchange_stop,
                'micro_time_exit' => $portfolio->micro_time_exit,
                'micro_max_hold_minutes' => $portfolio->micro_max_hold_minutes,
            ]);

            $clone->assets()->sync($portfolio->assets()->pluck('assets.id'));

            AuditLog::record('user:'.$request->user()->id, 'portfolio.cloned', $clone, [
                'from_portfolio_id' => $portfolio->id,
                'from_mode' => $portfolio->mode->value,
                'initial_cash' => $equity,
            ]);

            return $clone;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira simulada "'.$clone->name.'" criada com '.number_format($equity, 2, ',', '.').' USDT.']);

        return to_route('portfolios.show', $clone);
    }

    private static function cloneName(Portfolio $portfolio): string
    {
        $suffix = $portfolio->isLive() ? ' (simulada)' : ' (cópia)';

        return Str::limit($portfolio->name, 120 - mb_strlen($suffix), '').$suffix;
    }
}
