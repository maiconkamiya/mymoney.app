import { router } from '@inertiajs/vue3';
import { echo, echoIsConfigured, useEcho } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

export type PortfolioScope =
    | 'balance'
    | 'positions'
    | 'orders'
    | 'signals'
    | 'micro'
    | 'assets'
    | 'charts'
    | 'agents'
    | 'tokens';

// Cada escopo do evento `portfolio.changed` recarrega só as props que ele afeta.
export const scopeProps: Record<PortfolioScope, string[]> = {
    balance: ['portfolio', 'live', 'performance'],
    positions: ['positions', 'account_sync'],
    orders: ['tax_months', 'performance', 'live'],
    signals: ['signals', 'signals_total'],
    micro: ['micro'],
    assets: ['tracked_asset_ids', 'available_assets'],
    // Velas novas: o card de gráficos busca sozinho, a página não recarrega nada.
    charts: [],
    agents: ['agent_scores'],
    tokens: ['token_usage'],
};

/** Todos os blocos que o canal mantém em dia: recarregados juntos quando a conexão volta. */
export const realtimeProps = [...new Set(Object.values(scopeProps).flat())];

/**
 * - `ao-vivo`: conectado e inscrito no canal da carteira.
 * - `conectando`: abrindo ou reabrindo a conexão com o Reverb.
 * - `sem-conexao`: a conexão caiu ou falhou; a tela se atualiza por foto a cada tanto.
 * - `sem-permissao`: o servidor recusou o canal privado (sessão expirada ou carteira de outra conta).
 * - `desligado`: o build saiu sem as variáveis VITE_REVERB_*.
 */
export type RealtimeStatus =
    | 'ao-vivo'
    | 'conectando'
    | 'sem-conexao'
    | 'sem-permissao'
    | 'desligado';

const DEBOUNCE_MS = 400;
/** Rede de segurança só enquanto o websocket está fora. */
const FALLBACK_POLL_MS = 30_000;
/** Aba escondida por mais que isso: ao voltar, recarrega (o navegador pode ter dormido a conexão). */
const HIDDEN_RELOAD_MS = 30_000;

/**
 * Escuta o canal privado da carteira e recarrega, em lote, apenas os blocos
 * que mudaram (sem trocar de página nem perder a rolagem). Quando a conexão
 * cai e volta, recarrega tudo de uma vez, porque os eventos do intervalo se perderam.
 */
export function usePortfolioRealtime(portfolioId: number): {
    status: Ref<RealtimeStatus>;
    lastUpdate: Ref<Date | null>;
} {
    const status = ref<RealtimeStatus>('desligado');
    const lastUpdate = ref<Date | null>(null);

    if (!echoIsConfigured()) {
        return { status, lastUpdate };
    }

    status.value = 'conectando';

    const pending = new Set<string>();
    let timer: ReturnType<typeof setTimeout> | undefined;
    let poll: ReturnType<typeof setInterval> | undefined;
    let wasConnected = false;
    let hiddenAt: number | null = null;
    let stopConnection: (() => void) | undefined;

    const reload = (only: string[]) => {
        router.reload({
            only,
            onSuccess: () => (lastUpdate.value = new Date()),
        });
    };

    const flush = () => {
        const only = [...pending];
        pending.clear();

        if (only.length > 0) {
            reload(only);
        }
    };

    const reloadAll = () => {
        realtimeProps.forEach((prop) => pending.add(prop));
        clearTimeout(timer);
        timer = setTimeout(flush, DEBOUNCE_MS);
    };

    const setPolling = (on: boolean) => {
        if (on && poll === undefined) {
            poll = setInterval(reloadAll, FALLBACK_POLL_MS);
        } else if (!on && poll !== undefined) {
            clearInterval(poll);
            poll = undefined;
        }
    };

    const { channel } = useEcho<{ scopes: PortfolioScope[] }>(
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

    let lastState: string | null = null;

    const onConnection = (state: string) => {
        // O conector avisa a mesma mudança por mais de um evento do Pusher.
        if (state === lastState) {
            return;
        }

        lastState = state;

        if (state === 'connected') {
            if (status.value !== 'sem-permissao') {
                status.value = 'ao-vivo';
            }

            setPolling(false);

            // Reconectou: o que mudou enquanto estava fora não vai chegar por evento.
            if (wasConnected) {
                reloadAll();
            }

            wasConnected = true;

            return;
        }

        if (state === 'connecting' || state === 'reconnecting') {
            status.value = wasConnected ? 'sem-conexao' : 'conectando';
        } else {
            status.value = 'sem-conexao';
        }

        setPolling(true);
    };

    const onVisibility = () => {
        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();

            return;
        }

        if (hiddenAt !== null && Date.now() - hiddenAt > HIDDEN_RELOAD_MS) {
            reloadAll();
        }

        hiddenAt = null;
    };

    onMounted(() => {
        const connector = echo().connector;
        stopConnection = connector.onConnectionChange(onConnection);
        onConnection(connector.connectionStatus());

        // Canal privado recusado em /broadcasting/auth: sem isso a tela parece conectada e não recebe nada.
        channel()
            .error(() => {
                status.value = 'sem-permissao';
                setPolling(true);
            })
            .subscribed(() => {
                if (status.value === 'sem-permissao') {
                    status.value = 'ao-vivo';
                    setPolling(false);
                }
            });

        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        clearTimeout(timer);
        setPolling(false);
        stopConnection?.();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { status, lastUpdate };
}
