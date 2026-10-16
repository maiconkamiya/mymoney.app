<?php

namespace App\Trading\Live;

use App\Models\BrokerCredential;
use Illuminate\Support\Facades\Http;

/**
 * Checks a credential with the broker at registration time. A credential that
 * can withdraw funds is stored as such and never marked verified, so the live
 * brokers refuse it.
 */
class CredentialVerifier
{
    public function verify(BrokerCredential $credential): BrokerCredential
    {
        $canWithdraw = match ($credential->broker) {
            'binance' => $this->binanceCanWithdraw($credential),
            'mt5' => $this->mt5CanWithdraw($credential),
            default => throw new LiveTradingException("Broker [{$credential->broker}] não suporta credenciais reais."),
        };

        $credential->forceFill([
            'can_withdraw' => $canWithdraw,
            'verified_at' => $canWithdraw ? null : now(),
        ])->save();

        return $credential;
    }

    private function binanceCanWithdraw(BrokerCredential $credential): bool
    {
        // O testnet não tem saques nem o endpoint de restrições: basta a conta responder.
        if (config('trading.binance.testnet')) {
            (new BinanceClient($credential))->signed('GET', '/api/v3/account');

            return false;
        }

        $restrictions = (new BinanceClient($credential))->signed('GET', '/sapi/v1/account/apiRestrictions');

        if (empty($restrictions['enableSpotAndMarginTrading'])) {
            throw new LiveTradingException('A chave não tem permissão de negociação spot.');
        }

        return ! empty($restrictions['enableWithdrawals']);
    }

    private function mt5CanWithdraw(BrokerCredential $credential): bool
    {
        $account = Http::baseUrl((string) config('trading.mt5.gateway_url'))
            ->withToken((string) config('trading.mt5.gateway_token'))
            ->timeout(10)
            ->get('/account', ['login' => $credential->api_key])
            ->throw()
            ->json();

        if (empty($account['trade_allowed'])) {
            throw new LiveTradingException('A conta MT5 não permite negociação.');
        }

        return ! empty($account['can_withdraw']);
    }
}
