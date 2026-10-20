<?php

namespace App\Trading\Live;

/**
 * Reads balances from the signed account endpoint.
 */
class BinanceAccountReader
{
    /**
     * Balance per asset. With `$locked` the amount held in open orders counts too.
     *
     * @return array<string, float>
     */
    public function balances(BinanceClient $client, bool $locked = true): array
    {
        $account = $client->signed('GET', '/api/v3/account', ['omitZeroBalances' => 'true']);
        $balances = [];

        foreach ($account['balances'] ?? [] as $balance) {
            $amount = (float) $balance['free'] + ($locked ? (float) $balance['locked'] : 0.0);

            if ($amount > 0) {
                $balances[(string) $balance['asset']] = $amount;
            }
        }

        return $balances;
    }

    /**
     * USDT value of each wallet of the account (Spot, Funding, Earn, Margin, Futures, bots), the
     * same split that Binance's "estimated balance" adds up. Empty wallets are left out.
     *
     * @return array<string, float>
     */
    public function wallets(BinanceClient $client): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $client->signed('GET', '/sapi/v1/asset/wallet/balance', ['quoteAsset' => 'USDT']);
        $wallets = [];

        foreach ($rows as $row) {
            $value = (float) ($row['balance'] ?? 0);

            if ($value > 0 && isset($row['walletName'])) {
                $wallets[(string) $row['walletName']] = $value;
            }
        }

        return $wallets;
    }

    /**
     * Whether the account pays its spot fees in BNB with the discount (the
     * "Usar BNB para pagar taxas" switch on Binance). The account answers the
     * same for every pair; null when it does not say.
     */
    public function bnbDiscount(BinanceClient $client, string $symbol = 'BTCUSDT'): ?bool
    {
        $discount = $client->signed('GET', '/api/v3/account/commission', ['symbol' => $symbol])['discount'] ?? null;

        return is_array($discount) && isset($discount['enabledForAccount']) && ($discount['discountAsset'] ?? 'BNB') === 'BNB'
            ? (bool) $discount['enabledForAccount']
            : null;
    }

    /**
     * The last `$limit` orders of one pair, newest first, in any state.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(BinanceClient $client, string $symbol, int $limit = 20): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $client->signed('GET', '/api/v3/allOrders', ['symbol' => $symbol, 'limit' => $limit]);

        usort($rows, fn (array $a, array $b) => (int) ($b['time'] ?? 0) <=> (int) ($a['time'] ?? 0));

        return array_slice($rows, 0, $limit);
    }
}
