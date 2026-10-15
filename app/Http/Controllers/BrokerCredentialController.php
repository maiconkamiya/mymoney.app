<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BrokerCredential;
use App\Trading\Live\CredentialVerifier;
use App\Trading\Live\LiveTradingException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Registers a broker key and verifies it on the spot: a key that can withdraw
 * funds is kept flagged and unusable for real orders.
 */
class BrokerCredentialController extends Controller
{
    public function store(Request $request, CredentialVerifier $verifier): RedirectResponse
    {
        $data = $request->validate([
            'broker' => ['required', Rule::in(['binance', 'mt5'])],
            'label' => ['required', 'string', 'max:80'],
            'api_key' => ['required', 'string'],
            'api_secret' => ['required', 'string'],
        ]);

        $credential = BrokerCredential::create($data + ['user_id' => $request->user()->id, 'can_withdraw' => false]);

        try {
            $verifier->verify($credential);
        } catch (LiveTradingException|RequestException $e) {
            $credential->delete();

            return back()->withErrors(['api_key' => 'Não foi possível verificar a chave: '.$e->getMessage()]);
        }

        AuditLog::record('user:'.$request->user()->id, 'credential.registered', $credential, [
            'broker' => $credential->broker,
            'usable' => $credential->verified_at !== null,
        ]);

        Inertia::flash('toast', $credential->can_withdraw
            ? ['type' => 'error', 'message' => 'A chave permite saques e não será usada. Crie outra sem essa permissão.']
            : ['type' => 'success', 'message' => 'Chave verificada, sem permissão de saque.']);

        return back();
    }
}
