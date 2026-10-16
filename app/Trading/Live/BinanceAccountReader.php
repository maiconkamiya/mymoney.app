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
}
