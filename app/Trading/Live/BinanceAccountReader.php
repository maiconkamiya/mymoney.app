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
