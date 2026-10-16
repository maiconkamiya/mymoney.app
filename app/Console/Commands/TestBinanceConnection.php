<?php

namespace App\Console\Commands;

use App\Models\BrokerCredential;
use App\Models\User;
use App\Trading\Live\BinanceAccountReader;
use App\Trading\Live\BinanceClient;
use App\Trading\Live\LiveTradingException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('binance:testar {email : E-mail do usuário dono da chave}')]
#[Description('Faz uma chamada assinada de leitura de conta com a chave do usuário e mostra se a Binance responde deste servidor')]
class TestBinanceConnection extends Command
{
    public function handle(BinanceAccountReader $reader): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $credential = $user === null ? null : BrokerCredential::query()->where('user_id', $user->id)->where('broker', 'binance')->latest('id')->first();

        if ($credential === null) {
            $this->error('Esse usuário não tem chave da Binance cadastrada.');

            return self::FAILURE;
        }

        $this->line('Base da API: '.config('trading.binance.base_url').(config('trading.binance.testnet') ? ' (testnet)' : ''));

        try {
            $balances = $reader->balances(new BinanceClient($credential));
        } catch (LiveTradingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('A Binance respondeu. Ativos com saldo: '.count($balances).'. (Valores não são exibidos aqui.)');

        return self::SUCCESS;
    }
}
