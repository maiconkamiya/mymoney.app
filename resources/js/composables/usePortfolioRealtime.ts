import { router } from '@inertiajs/vue3';
import { echoIsConfigured, useEcho } from '@laravel/echo-vue';
import { onBeforeUnmount } from 'vue';

export type PortfolioScope =
    | 'balance'
    | 'positions'
    | 'orders'
    | 'signals'
    | 'micro';

// Cada escopo do evento `portfolio.changed` recarrega só as props que ele afeta.
export const scopeProps: Record<PortfolioScope, string[]> = {
    balance: ['portfolio', 'live', 'performance'],
    positions: ['positions', 'account_sync'],
    orders: ['tax_months', 'performance', 'live'],
    signals: ['signals', 'signals_total', 'agent_scores'],
    micro: ['micro'],
};

const DEBOUNCE_MS = 400;

/**
 * Escuta o canal privado da carteira e recarrega, em lote, apenas os blocos
 * que mudaram (sem trocar de página nem perder a rolagem).
 */
export function usePortfolioRealtime(portfolioId: number): void {
    if (!echoIsConfigured()) {
        return;
    }

    const pending = new Set<string>();
    let timer: ReturnType<typeof setTimeout> | undefined;

    const flush = () => {
        const only = [...pending];
        pending.clear();

        if (only.length > 0) {
            router.reload({ only });
        }
    };

    useEcho<{ scopes: PortfolioScope[] }>(
        `portfolios.${portfolioId}`,
        '.portfolio.changed',
        (event) => {
            for (const scope of event.scopes) {
                (scopeProps[scope] ?? []).forEach((prop) => pending.add(prop));
            }

            clearTimeout(timer);
            timer = setTimeout(flush, DEBOUNCE_MS);
        },
    );

    onBeforeUnmount(() => clearTimeout(timer));
}
