<?php

namespace App\Http\Controllers;

use App\Enums\Market;
use App\MarketData\BinanceSymbols;
use App\MarketData\BrapiB3;
use App\Models\Asset;
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
    public function index(AssetRegistry $registry): Response
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
                'blockers' => $registry->blockers($asset),
            ]);

        return Inertia::render('Assets', ['assets' => $assets]);
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

    public function destroy(Request $request, Asset $asset, AssetRegistry $registry): RedirectResponse
    {
        $result = $registry->remove($asset, 'user:'.$request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$asset->symbol} {$result}."]);

        return back();
    }
}
