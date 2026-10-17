<script setup lang="ts">
import { chartScenarioLabels, money } from '@/lib/trading';
import type { ChartAnalysis } from '@/types';

defineProps<{
    analysis: ChartAnalysis;
    market: string;
}>();

const prices = (list: number[], market: string) =>
    list.length ? list.map((price) => money(price, market)).join(' · ') : '-';
</script>

<template>
    <dl class="mt-2 space-y-0.5 text-xs tabular-nums">
        <div v-if="analysis.scenario" class="flex gap-2">
            <dt class="text-muted-foreground">Cenário</dt>
            <dd class="font-medium">
                {{
                    chartScenarioLabels[analysis.scenario] ?? analysis.scenario
                }}
            </dd>
        </div>
        <div class="flex gap-2">
            <dt class="text-muted-foreground">Suportes</dt>
            <dd>{{ prices(analysis.supports, market) }}</dd>
        </div>
        <div class="flex gap-2">
            <dt class="text-muted-foreground">Resistências</dt>
            <dd>{{ prices(analysis.resistances, market) }}</dd>
        </div>
        <div v-if="analysis.timeframes.length" class="flex gap-2">
            <dt class="text-muted-foreground">Gráficos usados</dt>
            <dd>{{ analysis.timeframes.join(', ') }}</dd>
        </div>
        <div v-if="analysis.omitted_timeframes.length" class="flex gap-2">
            <dt class="text-muted-foreground">Sem histórico</dt>
            <dd>{{ analysis.omitted_timeframes.join(', ') }}</dd>
        </div>
    </dl>
</template>
