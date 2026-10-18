import { echoIsConfigured, useEchoPublic } from '@laravel/echo-vue';
import { reactive } from 'vue';

/** Último preço de cada par recebido no canal público `mercado`, compartilhado entre os cards. */
const prices = reactive<Record<string, number>>({});

/**
 * Preços de agora vindos do coletor do servidor (`market:stream-binance`),
 * sem recarregar a página. Sem preço recebido ainda, quem usa cai no valor do servidor.
 */
export function useMarketPrices(): Record<string, number> {
    if (echoIsConfigured()) {
        useEchoPublic<{ symbol: string; price: number }>(
            'mercado',
            '.cotacao.atualizada',
            (event) => {
                prices[event.symbol] = event.price;
            },
        );
    }

    return prices;
}
