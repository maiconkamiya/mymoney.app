<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\MarketData\BinanceSymbols;
use App\MarketData\BrapiB3;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\OpportunityScan;
use App\Models\Portfolio;
use App\Settings\SystemSettings;
use App\Trading\Assets\AssetRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function index(Request $request, AssetRegistry $registry, SystemSettings $settings): Response
    {
        $assets = Asset::query()
            ->where('active', true)
            ->where('is_benchmark', false)
            ->orderBy('market')
            ->orderBy('symbol')
            ->get()
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'symbol' => $asset->symbol,
                'market' => $asset->market->value,
                'name' => $asset->name,
                'lot_size' => $asset->lot_size,
                'tick_size' => $asset->tick_size,
                'min_qty' => $asset->min_qty,
                'min_notional' => $asset->min_notional,
                'discovered' => $asset->discovered,
                'trading_ends_on' => $asset->trading_ends_on?->toDateString(),
                'trading_ended' => $asset->tradingEnded(),
                'blockers' => $registry->blockers($asset),
            ]);

        return Inertia::render('Assets', [
            'assets' => $assets,
            'can_edit_trading_end' => $settings->canEdit($request->user()),
            'explorer' => fn () => $this->explorer($request),
        ]);
    }

    /**
     * A última varredura do explorador e as carteiras de cripto do usuário, para os botões.
     *
     * @return array<string, mixed>
     */
    private function explorer(Request $request): array
    {
        $scan = OpportunityScan::query()->whereNull('error')->latest('id')->first();
        $failed = OpportunityScan::query()->latest('id')->first();
        $registered = Asset::query()->where('market', Market::Crypto)->where('active', true)->pluck('discovered', 'symbol');

        return [
            'enabled' => (bool) config('explorer.enabled'),
            'agents_enabled' => (bool) config('agents.enabled'),
            'interval_minutes' => (int) config('explorer.interval_minutes'),
            'swarm_top' => (int) config('explorer.swarm.top'),
            'swarm_min_score' => (float) config('explorer.swarm.min_score'),
            'swarm_max_per_day' => (int) config('explorer.swarm.max_per_day'),
            'swarm_cooldown_hours' => (int) config('explorer.swarm.cooldown_hours'),
            'scanned_at' => $scan?->created_at->toIso8601String(),
            'pairs_seen' => $scan?->pairs_seen,
            'pairs_passed' => $scan?->pairs_passed,
            'ai_model' => $scan?->ai_model,
            'error' => $failed?->error,
            'opportunities' => $scan === null ? [] : $scan->opportunities->take(max(1, (int) config('explorer.top')))->map(fn (Opportunity $o) => [
                'id' => $o->id,
                'rank' => $o->rank,
                'symbol' => $o->symbol,
                'base' => $o->base,
                'score' => $o->score,
                'price' => $o->price,
                'change_24h_pct' => $o->change_24h_pct,
                'quote_volume' => $o->quote_volume,
                'spread_pct' => $o->spread_pct,
                'volume_accel' => $o->volume_accel,
                'breakout' => $o->breakout,
                'extension_atr' => $o->extension_atr,
                'reasons' => $o->reasons,
                'ai_note' => $o->ai_note,
                'swarm_sent_at' => $o->swarm_sent_at?->toIso8601String(),
                'status' => match ($registered->get($o->symbol)) {
                    null => null,
                    true => 'discovered',
                    default => 'registered',
                },
            ])->values(),
            'portfolios' => Portfolio::query()
                ->where('user_id', $request->user()->id)
                ->where('market', Market::Crypto)
                ->orderBy('name')
                ->get()
                ->map(fn (Portfolio $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'mode' => $p->mode->value,
                    'running' => $p->isRunning(),
                    'auto_swarm' => $p->explorer_auto_swarm,
                ])->values(),
        ];
    }

    /**
     * Pesquisa na Binance (pares em USDT) ou na brapi (B3) para o usuário escolher o que cadastrar.
     */
    public function search(Request $request, BinanceSymbols $binance, BrapiB3 $brapi): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::enum(Market::class)],
            'q' => ['nullable', 'string', 'max:30'],
        ]);

        $query = (string) ($data['q'] ?? '');

        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        try {
            $results = $data['market'] === Market::Crypto->value
                ? array_map(fn (array $pair) => ['symbol' => $pair['symbol'], 'name' => $pair['base']], $binance->search($query))
                : array_map(fn (array $row) => ['symbol' => $row['symbol'], 'name' => $row['name']], $brapi->search($query));
        } catch (ConnectionException|RequestException) {
            return response()->json(['message' => 'Não foi possível consultar a fonte de dados agora. Tente de novo em instantes.'], 503);
        }

        $registered = Asset::query()->where('market', $data['market'])->where('active', true)->pluck('symbol')->all();

        return response()->json(['results' => array_map(
            fn (array $row) => $row + ['registered' => in_array($row['symbol'], $registered, true)],
            $results,
        )]);
    }

    public function store(Request $request, AssetRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::enum(Market::class)],
            'symbol' => ['required', 'string', 'max:20'],
        ]);

        $asset = $registry->add(Market::from($data['market']), $data['symbol'], 'user:'.$request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$asset->symbol} cadastrado. O coletor e o scanner passam a cobri-lo em até 1 minuto."]);

        return back();
    }

    /**
     * Define ou limpa a data em que a corretora deixa de negociar o par. Ela vende
     * as posições de todas as contas nesse dia, por isso só o administrador muda.
     */
    public function updateTradingEnd(Request $request, Asset $asset, SystemSettings $settings): RedirectResponse
    {
        abort_unless($settings->canEdit($request->user()), 403);

        $data = $request->validate([
            'trading_ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $before = $asset->trading_ends_on?->toDateString();
        $asset->update(['trading_ends_on' => $data['trading_ends_on'] ?? null]);
        $ends = $asset->trading_ends_on;
        $after = $ends?->toDateString();

        AuditLog::record('user:'.$request->user()->id, 'asset.trading_end_updated', $asset, ['symbol' => $asset->symbol, 'from' => $before, 'to' => $after]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $ends === null
            ? "{$asset->symbol} sem data de fim de negociação."
            : "{$asset->symbol} encerra em ".$ends->format('d/m/Y').'. Nesse dia o app para de comprar e vende o que houver nas carteiras.']);

        return back();
    }

    public function destroy(Request $request, Asset $asset, AssetRegistry $registry): RedirectResponse
    {
        $result = $registry->remove($asset, 'user:'.$request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$asset->symbol} {$result}."]);

        return back();
    }
}
