<script setup lang="ts">
import { computed } from 'vue';
import InfoTip from '@/components/portfolio/InfoTip.vue';
import type { AccountSync } from '@/types/trading';

const props = defineProps<{
    equity: number;
    sync: AccountSync;
}>();

const walletLabels: Record<string, string> = {
    Funding: 'Fundos (Funding)',
    Earn: 'Earn',
    'Cross Margin': 'Margem cruzada',
    'Isolated Margin': 'Margem isolada',
    'USDⓈ-M Futures': 'Futuros USDⓈ-M',
    'COIN-M Futures': 'Futuros COIN-M',
    Options: 'Opções',
    'Trading Bots': 'Robôs de negociação',
    'Copy Trading': 'Copy Trading',
};

function usdt(value: number): string {
    return `${value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USDT`;
}

// O que a Binance soma e o app não: poeira e moedas sem par em USDT da Spot, e as outras carteiras da conta.
const parts = computed(() => {
    const rows: { label: string; value: number }[] = [];

    if (props.sync.dust_total > 0) {
        rows.push({
            label: 'Poeira na Spot (abaixo de 1 USDT)',
            value: props.sync.dust_total,
        });
    }

    if (props.sync.not_mirrored_total > 0) {
        rows.push({
            label: 'Moedas da Spot que o app não negocia',
            value: props.sync.not_mirrored_total,
        });
    }

    for (const [name, value] of Object.entries(props.sync.wallets ?? {})) {
        if (name !== 'Spot' && value > 0) {
            rows.push({
                label: `Carteira ${walletLabels[name] ?? name}`,
                value,
            });
        }
    }

    return rows;
});

const total = computed(() => {
    if (!props.sync.wallets) {
        return null;
    }

    return Object.values(props.sync.wallets).reduce(
        (sum, value) => sum + value,
        0,
    );
});

// O resto vem das cotações: a Binance usa o preço dela na hora da sincronização, o app o preço de agora.
const priceGap = computed(() => {
    if (total.value === null) {
        return 0;
    }

    return (
        total.value -
        props.equity -
        parts.value.reduce((sum, row) => sum + row.value, 0)
    );
});
</script>

<template>
    <div
        v-if="total !== null || parts.length > 0"
        class="mt-2 flex items-center gap-1 text-xs text-muted-foreground"
        data-test="binance-total"
    >
        <p v-if="total !== null">
            Total na conta Binance:
            <span class="font-medium text-foreground tabular-nums">{{
                usdt(total)
            }}</span>
        </p>
        <p v-else>
            Fora do patrimônio do app:
            {{ usdt(parts.reduce((sum, row) => sum + row.value, 0)) }}
        </p>
        <InfoTip label="Por que o total da Binance é diferente">
            <p>
                O patrimônio do app é o que ele opera: USDT e moedas acima de 1
                USDT com par em USDT da carteira Spot, incluindo o que está
                travado em ordens abertas.
            </p>
            <ul class="mt-2 space-y-0.5">
                <li class="flex justify-between gap-3">
                    <span>Patrimônio no app</span>
                    <span class="whitespace-nowrap tabular-nums">{{
                        usdt(equity)
                    }}</span>
                </li>
                <li
                    v-for="row in parts"
                    :key="row.label"
                    class="flex justify-between gap-3"
                >
                    <span>{{ row.label }}</span>
                    <span class="whitespace-nowrap tabular-nums">{{
                        usdt(row.value)
                    }}</span>
                </li>
                <li
                    v-if="Math.abs(priceGap) >= 0.01"
                    class="flex justify-between gap-3"
                >
                    <span>Diferença de cotação desde a sincronização</span>
                    <span class="whitespace-nowrap tabular-nums">{{
                        usdt(priceGap)
                    }}</span>
                </li>
            </ul>
            <p v-if="total === null" class="mt-2">
                As outras carteiras da Binance não puderam ser lidas.
            </p>
            <p class="mt-2">
                Se a Binance mostrar o saldo em BRL ou BTC, troque para USD para
                comparar. A lista das moedas em poeira fica em Posições abertas.
            </p>
        </InfoTip>
    </div>
</template>
