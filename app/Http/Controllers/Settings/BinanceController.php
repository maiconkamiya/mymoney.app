<?php

namespace App\Http\Controllers\Settings;

use App\Enums\PortfolioStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Models\Portfolio;
use App\Trading\Live\BinanceAccountSync;
use App\Trading\Live\CredentialVerifier;
use App\Trading\Live\LiveTradingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Each user keeps their own Binance key. It is stored encrypted, checked
 * against the account before it is saved and never sent back to the browser:
 * the page only knows that a key exists and since when.
 */
class BinanceController extends Controller
{
    public function edit(Request $request): Response
    {
        $credential = $this->credentialOf($request);

        return Inertia::render('settings/Binance', [
            'credential' => $credential === null ? null : [
                'label' => $credential->label,
                'configured_at' => $credential->created_at?->toIso8601String(),
                'verified' => $credential->verified_at !== null && ! $credential->can_withdraw,
            ],
            'portfolio_id' => Portfolio::query()
                ->where('user_id', $request->user()->id)
                ->where('broker', 'binance')
                ->value('id'),
            'testnet' => (bool) config('trading.binance.testnet'),
            'live_enabled' => (bool) config('trading.live.enabled'),
        ]);
    }

    public function update(Request $request, CredentialVerifier $verifier, BinanceAccountSync $sync): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'api_key' => ['required', 'string', 'max:200'],
            'api_secret' => ['required', 'string', 'max:200'],
        ]);

        $credential = BrokerCredential::create([
            'user_id' => $request->user()->id,
            'broker' => 'binance',
            'label' => ($data['label'] ?? null) ?: 'Binance',
            'api_key' => trim($data['api_key']),
            'api_secret' => trim($data['api_secret']),
            'can_withdraw' => false,
        ]);

        try {
            $verifier->verify($credential);
        } catch (LiveTradingException $e) {
            $credential->delete();

            return back()->withErrors(['api_key' => $e->getMessage()]);
        }

        if ($credential->can_withdraw) {
            $credential->delete();

            return back()->withErrors(['api_key' => 'A chave permite saques e não será guardada. Crie outra só com leitura e negociação spot.']);
        }

        BrokerCredential::query()
            ->where('user_id', $request->user()->id)
            ->where('broker', 'binance')
            ->whereKeyNot($credential->id)
            ->delete();

        $portfolio = $sync->portfolioFor($credential);
        AuditLog::record('user:'.$request->user()->id, 'credential.registered', $credential, ['broker' => 'binance', 'portfolio_id' => $portfolio->id]);

        try {
            $result = $sync->sync($portfolio);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Chave verificada. Carteira sincronizada: {$result['positions']} ativo(s) e saldo em USDT."]);
        } catch (LiveTradingException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Chave guardada, mas a leitura da conta falhou: '.$e->getMessage()]);
        }

        return back();
    }

    public function sync(Request $request, BinanceAccountSync $sync): RedirectResponse
    {
        $portfolio = Portfolio::query()
            ->where('user_id', $request->user()->id)
            ->where('broker', 'binance')
            ->firstOrFail();

        try {
            $result = $sync->sync($portfolio);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Carteira sincronizada: {$result['positions']} ativo(s)."]);
        } catch (LiveTradingException $e) {
            return back()->withErrors(['sync' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $credential = $this->credentialOf($request);

        if ($credential !== null) {
            Portfolio::query()
                ->where('user_id', $request->user()->id)
                ->where('broker_credential_id', $credential->id)
                ->update(['status' => PortfolioStatus::Paused]);

            AuditLog::record('user:'.$request->user()->id, 'credential.removed', $credential, ['broker' => 'binance']);
            $credential->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Chave da Binance removida. A carteira ficou pausada.']);

        return back();
    }

    private function credentialOf(Request $request): ?BrokerCredential
    {
        return BrokerCredential::query()
            ->where('user_id', $request->user()->id)
            ->where('broker', 'binance')
            ->latest('id')
            ->first();
    }
}
