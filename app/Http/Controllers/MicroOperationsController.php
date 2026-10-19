<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Http\Requests\UpdateMicroOperationsRequest;
use App\Models\AuditLog;
use App\Models\Portfolio;
use App\Trading\Micro\MicroTrader;
use App\Trading\PortfolioLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Settings of the "Micro operações" mode. Owner only.
 */
class MicroOperationsController extends Controller
{
    public function update(UpdateMicroOperationsRequest $request, Portfolio $portfolio): RedirectResponse
    {
        $data = $request->validated();

        $portfolio->update([
            'micro_enabled' => (bool) $data['micro_enabled'],
            'micro_order_value' => $data['micro_order_value'],
            'micro_net_margin_pct' => $data['micro_net_margin_pct'],
            'micro_daily_target_pct' => $data['micro_daily_target_pct'] ?? null,
            'daily_loss_pct' => $data['daily_loss_pct'] ?? null,
            'micro_daily_operations' => $data['micro_daily_operations'] ?? null,
            'micro_wallet_pct' => $data['micro_wallet_pct'] ?? 100,
            'micro_rule' => $data['micro_rule'] ?? $portfolio->micro_rule,
            'micro_entry_mode' => $data['micro_entry_mode'] ?? $portfolio->micro_entry_mode,
            'micro_limit_seconds' => $data['micro_limit_seconds'] ?? $portfolio->micro_limit_seconds,
        ]);

        AuditLog::record('user:'.$request->user()->id, 'portfolio.micro_settings', $portfolio, [
            ...$data,
            'live' => $portfolio->isLive(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $portfolio->micro_enabled
            ? 'Micro operações ligadas: o agente opera nos pares mais voláteis dentro dos seus valores.'
            : 'Micro operações salvas (desligadas). Ciclos abertos seguem até o alvo, o stop ou o tempo limite.']);

        return back();
    }

    /**
     * Marks or unmarks one asset to be sold with profit and no longer bought.
     */
    public function liquidate(Request $request, Portfolio $portfolio): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);
        abort_unless($portfolio->market === Market::Crypto, 422);

        $data = $request->validate([
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->where('market', Market::Crypto->value)],
            'liquidate' => ['required', 'boolean'],
        ]);

        $assetId = (int) $data['asset_id'];
        $ids = array_values(array_diff($portfolio->micro_liquidate_asset_ids ?? [], [$assetId]));

        if ($request->boolean('liquidate')) {
            $ids[] = $assetId;
        }

        $portfolio->update(['micro_liquidate_asset_ids' => $ids === [] ? null : $ids]);

        AuditLog::record('user:'.$request->user()->id, 'portfolio.micro_liquidate', $portfolio, [
            'asset_id' => $assetId,
            'liquidate' => $request->boolean('liquidate'),
            'live' => $portfolio->isLive(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('liquidate')
            ? 'O ativo será vendido assim que der lucro líquido, e o modo não compra mais ele.'
            : 'Liquidação cancelada: o ativo volta a poder ser comprado pelo modo.']);

        return back();
    }

    /**
     * "Liquidar agora": sells one open position at market, even at a loss.
     */
    public function liquidateNow(Request $request, Portfolio $portfolio, MicroTrader $trader): RedirectResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);
        abort_unless($portfolio->market === Market::Crypto, 422);

        $data = $request->validate([
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->where('market', Market::Crypto->value)],
        ]);

        $position = $portfolio->openPositions()->where('asset_id', $data['asset_id'])->with(['asset', 'portfolio'])->first();
        $actor = 'user:'.$request->user()->id;

        // O motor em tempo real pode estar mexendo nesta carteira agora: espera ele terminar a passada.
        $outcome = $position === null
            ? 'Não há posição aberta deste ativo.'
            : PortfolioLock::run($portfolio->id, fn () => $trader->liquidateNow($portfolio, $position->refresh(), $actor), wait: 10);
        $outcome = $outcome === false ? 'O motor está ocupado com esta carteira; tente de novo em alguns segundos.' : $outcome;

        AuditLog::record($actor, 'portfolio.micro_liquidate_now', $portfolio, [
            'asset_id' => (int) $data['asset_id'],
            'sold' => ! is_string($outcome),
            'detail' => is_string($outcome) ? $outcome : null,
            'live' => $portfolio->isLive(),
        ]);

        Inertia::flash('toast', is_string($outcome)
            ? ['type' => 'error', 'message' => 'Venda não feita: '.$outcome]
            : ['type' => 'success', 'message' => sprintf('%s vendido a mercado a %s.', $position?->asset->symbol, rtrim(rtrim(number_format((float) $outcome->price, 8, ',', '.'), '0'), ','))]);

        return back();
    }
}
