<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { money } from '@/lib/trading';
import type { BrokerOrder, BrokerOrderStatus } from '@/types';

const props = defineProps<{
    orders: BrokerOrder[];
    errors: Record<string, string>;
}>();

const statusLabels: Record<BrokerOrderStatus, string> = {
    pending: 'Aberta',
    filled: 'Executada',
    partially_filled: 'Parcial',
    cancelled: 'Cancelada',
    expired: 'Expirada',
    rejected: 'Recusada',
};

const statusClass: Record<BrokerOrderStatus, string> = {
    pending: 'text-sky-400',
    filled: 'text-emerald-400',
    partially_filled: 'text-amber-400',
    cancelled: 'text-muted-foreground',
    expired: 'text-muted-foreground',
    rejected: 'text-red-400',
};

const typeLabels: Record<string, string> = {
    limit: 'limitada',
    market: 'a mercado',
    limit_maker: 'limitada',
    stop_loss_limit: 'stop limitada',
    take_profit_limit: 'alvo limitada',
};

const symbols = computed(() =>
    [...new Set(props.orders.map((order) => order.symbol))].sort(),
);

const pair = ref('');

// O par escolhido saiu da lista na sincronização seguinte: volta para todos.
watch(symbols, (now) => {
    if (pair.value !== '' && !now.includes(pair.value)) {
        pair.value = '';
    }
});

const visible = computed(() =>
    pair.value === ''
        ? props.orders
        : props.orders.filter((order) => order.symbol === pair.value),
);

const errorList = computed(() => Object.entries(props.errors));

function when(time: string | null): string {
    return time === null
        ? '-'
        : new Date(time).toLocaleString('pt-BR', {
              day: '2-digit',
              month: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
          });
}

function qty(value: number): string {
    return new Intl.NumberFormat('pt-BR', {
        maximumFractionDigits: 8,
    }).format(value);
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-medium text-foreground">
                Ordens na Binance
            </h3>
            <select
                v-if="symbols.length > 1"
                v-model="pair"
                aria-label="Par das ordens"
                class="h-8 rounded-md border border-input bg-transparent px-2 text-xs shadow-xs"
            >
                <option value="">Todos os pares</option>
                <option v-for="symbol in symbols" :key="symbol" :value="symbol">
                    {{ symbol }}
                </option>
            </select>
        </div>
        <p class="text-xs">
            Até as últimas 20 ordens de cada par da carteira, lidas a cada
            sincronização.
        </p>

        <p v-if="orders.length === 0" class="text-xs">
            Nenhuma ordem encontrada na Binance para os pares da carteira.
        </p>

        <ul v-else class="max-h-96 divide-y overflow-y-auto rounded-lg border">
            <li
                v-for="order in visible"
                :key="order.id"
                class="grid gap-0.5 px-3 py-2 text-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium text-foreground">
                        {{ order.side === 'buy' ? 'Compra' : 'Venda' }}
                        {{ order.symbol }}
                        <span class="font-normal text-muted-foreground">
                            {{ typeLabels[order.type] ?? order.type }}
                        </span>
                    </span>
                    <span :class="statusClass[order.status]">
                        {{ statusLabels[order.status] }}
                    </span>
                </div>
                <div class="flex flex-wrap justify-between gap-x-3">
                    <span>
                        {{ qty(order.executed_qty) }} de {{ qty(order.qty) }}
                        <template v-if="order.avg_price !== null">
                            a {{ money(order.avg_price, 'crypto') }}
                        </template>
                        <template v-else-if="order.price !== null">
                            a {{ money(order.price, 'crypto') }}
                        </template>
                    </span>
                    <span class="tabular-nums">{{ when(order.time) }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span>
                        {{
                            order.from_app
                                ? order.app_order_id !== null
                                    ? `Do app (ordem ${order.app_order_id})`
                                    : 'Do app'
                                : 'Feita fora do app'
                        }}
                    </span>
                    <span v-if="order.value > 0">
                        {{ money(order.value, 'crypto') }} USDT
                    </span>
                </div>
            </li>
        </ul>

        <ul v-if="errorList.length > 0" class="space-y-1 text-xs text-red-400">
            <li v-for="[symbol, message] in errorList" :key="symbol">
                {{ symbol }}: não foi possível ler as ordens ({{ message }}).
            </li>
        </ul>
    </div>
</template>
