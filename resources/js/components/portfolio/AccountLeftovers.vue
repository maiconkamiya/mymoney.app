<script setup lang="ts">
import { computed } from 'vue';
import InfoTip from '@/components/portfolio/InfoTip.vue';
import type { AccountSync } from '@/types/trading';

const props = defineProps<{ sync: AccountSync }>();

const unpriced = computed(() =>
    props.sync.not_mirrored.filter((item) => item.status === 'sem_cotacao'),
);
const unpaired = computed(() =>
    props.sync.not_mirrored.filter((item) => item.status !== 'sem_cotacao'),
);
</script>

<template>
    <!-- Resumo numa linha; a lista de moedas abre no ícone (hover ou toque). -->
    <div
        v-if="sync.dust.length > 0 || sync.not_mirrored.length > 0"
        class="flex flex-wrap items-center gap-x-3 gap-y-1"
        data-test="account-leftovers"
    >
        <span
            v-if="sync.dust.length > 0"
            class="inline-flex items-center gap-1"
        >
            Poeira: {{ sync.dust.length }} ativo(s),
            {{ sync.dust_total.toFixed(2) }} USDT
            <InfoTip label="Ver as moedas em poeira">
                <p class="mb-2 font-medium">
                    Poeira (abaixo de 1 USDT), total
                    {{ sync.dust_total.toFixed(2) }} USDT
                </p>
                <ul class="space-y-0.5 tabular-nums">
                    <li
                        v-for="item in sync.dust"
                        :key="item.coin"
                        class="flex justify-between gap-3"
                    >
                        <span>{{ item.coin }}</span>
                        <span>{{ item.qty }}</span>
                    </li>
                </ul>
            </InfoTip>
        </span>
        <span
            v-if="sync.not_mirrored.length > 0"
            class="inline-flex items-center gap-1"
        >
            Fora do app: {{ sync.not_mirrored.length }} ativo(s)
            <InfoTip label="Ver as moedas que o app não espelha">
                <template v-if="unpriced.length > 0">
                    <p class="mb-1 font-medium">
                        Sem cotação: sem par com USDT, BTC, BNB ou BRL para
                        estimar o valor.
                    </p>
                    <ul class="mb-2 space-y-0.5 tabular-nums">
                        <li
                            v-for="item in unpriced"
                            :key="item.coin"
                            class="flex justify-between gap-3"
                        >
                            <span>{{ item.coin }}</span>
                            <span>{{ item.qty }}</span>
                        </li>
                    </ul>
                </template>
                <template v-if="unpaired.length > 0">
                    <p class="mb-1 font-medium">
                        Sem par em USDT: valor estimado por outro par, o app não
                        negocia.
                    </p>
                    <ul class="space-y-0.5 tabular-nums">
                        <li
                            v-for="item in unpaired"
                            :key="item.coin"
                            class="flex justify-between gap-3"
                        >
                            <span>{{ item.coin }} {{ item.qty }}</span>
                            <span v-if="item.value !== null"
                                >{{ item.value.toFixed(2) }} USDT</span
                            >
                        </li>
                    </ul>
                </template>
            </InfoTip>
        </span>
    </div>
</template>
