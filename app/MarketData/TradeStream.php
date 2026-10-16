<?php

namespace App\MarketData;

use Closure;

/**
 * Fonte de negócios em tempo real. A implementação real fala com a Binance;
 * os testes usam uma fonte falsa.
 */
interface TradeStream
{
    /**
     * Bloqueia ouvindo os negócios até `$onTick` devolver false ou a conexão cair.
     * `$onTrade` recebe (símbolo, preço, quantidade, instante do negócio em ms);
     * `$onTick` é chamado cerca de uma vez por segundo, mesmo sem negócios.
     *
     * @param  list<string>  $symbols
     * @param  Closure(string, float, float, int): void  $onTrade
     * @param  Closure(): bool  $onTick
     */
    public function listen(array $symbols, Closure $onTrade, Closure $onTick): void;
}
