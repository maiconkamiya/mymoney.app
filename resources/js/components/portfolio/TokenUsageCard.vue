<script setup lang="ts">
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { TokenUsage } from '@/types';

const props = defineProps<{
    usage: TokenUsage;
}>();

const compact = new Intl.NumberFormat('pt-BR', {
    notation: 'compact',
    maximumFractionDigits: 1,
});
const whole = new Intl.NumberFormat('pt-BR');
const dollars = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const tiles = computed(() => {
    const { today, week, month, estimate } = props.usage;
    const perDay = estimate.days > 0 ? month.total / estimate.days : 0;

    return [
        {
            key: 'today',
            label: 'Hoje',
            period: today,
            note: `${whole.format(today.calls)} ${today.calls === 1 ? 'chamada' : 'chamadas'}`,
        },
        {
            key: 'week',
            label: 'Semana',
            period: week,
            note: `${whole.format(week.calls)} ${week.calls === 1 ? 'chamada' : 'chamadas'}`,
        },
        {
            key: 'month',
            label: 'Mês',
            period: month,
            note: `${whole.format(month.calls)} ${month.calls === 1 ? 'chamada' : 'chamadas'}`,
        },
        {
            key: 'estimate',
            label: 'Estimativa do mês',
            period: estimate,
            note: `média de ${compact.format(Math.round(perDay))} por dia`,
            estimate: true,
        },
    ];
});

const sinceLabel = computed(() =>
    props.usage.since
        ? new Intl.DateTimeFormat('pt-BR', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              timeZone: 'UTC',
          }).format(new Date(props.usage.since))
        : null,
);
</script>

<template>
    <section class="space-y-3" data-test="token-usage">
        <Heading
            variant="small"
            title="Tokens da IA"
            description="Entrada e saída das chamadas dos agentes nos sinais desta carteira."
        />

        <TooltipProvider :delay-duration="150">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Tooltip v-for="tile in tiles" :key="tile.key">
                    <TooltipTrigger as-child>
                        <div
                            tabindex="0"
                            class="rounded-2xl border bg-card p-4 transition-colors outline-none hover:border-foreground/20 focus-visible:border-foreground/30"
                            :class="
                                tile.estimate
                                    ? 'border-dashed bg-card/40'
                                    : undefined
                            "
                            :data-test="`token-usage-${tile.key}`"
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ tile.label }}
                            </p>
                            <p
                                class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
                            >
                                <span
                                    v-if="tile.estimate"
                                    class="text-muted-foreground"
                                    >~</span
                                >{{ compact.format(tile.period.total) }}
                            </p>
                            <p
                                class="mt-1 flex flex-wrap gap-x-2 text-xs text-muted-foreground tabular-nums"
                            >
                                <span>{{
                                    dollars.format(tile.period.cost)
                                }}</span>
                                <span>{{ tile.note }}</span>
                            </p>
                        </div>
                    </TooltipTrigger>
                    <TooltipContent class="tabular-nums">
                        <p>Entrada: {{ whole.format(tile.period.input) }}</p>
                        <p>Saída: {{ whole.format(tile.period.output) }}</p>
                        <p>Total: {{ whole.format(tile.period.total) }}</p>
                        <p v-if="tile.estimate">
                            Média de {{ usage.estimate.days }}
                            {{ usage.estimate.days === 1 ? 'dia' : 'dias' }}
                            deste mês projetada até o dia
                            {{ usage.estimate.days_in_month }}.
                        </p>
                    </TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>

        <p class="text-xs text-muted-foreground">
            <template v-if="sinceLabel">
                Conta desde {{ sinceLabel }}.
            </template>
            <template v-else>
                Nenhuma chamada de IA nesta carteira ainda.
            </template>
            Dias e semana (a partir de segunda) em UTC. O explorador de
            oportunidades e a triagem de notícias são da conta toda e não entram
            aqui.
        </p>
    </section>
</template>
