<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import SignalDecisionController from '@/actions/App/Http/Controllers/SignalDecisionController';
import ChartReading from '@/components/portfolio/ChartReading.vue';
import SignalCalculation from '@/components/portfolio/SignalCalculation.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { actionLabels, agentLabels, money, stageLabels } from '@/lib/trading';
import type { SignalRow } from '@/types';

const page = usePage();

// Na carteira, decidir um sinal recarrega só os blocos que ele muda.
const decisionOptions = {
    preserveScroll: true,
    ...(page.component === 'portfolios/Show'
        ? {
              only: [
                  'portfolio',
                  'live',
                  'positions',
                  'signals',
                  'signals_total',
                  'micro',
              ],
          }
        : {}),
};

const props = withDefaults(
    defineProps<{
        signal: SignalRow;
        market: string;
        defaultOpen?: boolean;
    }>(),
    { defaultOpen: false },
);

const open = ref(props.defaultOpen);

const stageVariant = (stage: string) =>
    stage === 'approved'
        ? 'default'
        : stage === 'rejected'
          ? 'destructive'
          : 'secondary';

const when = (iso: string) =>
    new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <Collapsible
        v-model:open="open"
        as="article"
        class="rounded-2xl border bg-card"
    >
        <CollapsibleTrigger
            class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl p-4 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <span class="font-medium">{{ signal.symbol }}</span>
            <Badge :variant="stageVariant(signal.stage)">
                {{ stageLabels[signal.stage] ?? signal.stage }}
            </Badge>
            <span
                v-if="signal.side"
                class="text-sm text-muted-foreground tabular-nums"
            >
                {{ actionLabels[signal.side] }} · score
                {{ signal.score?.toFixed(2) }}
            </span>
            <span
                class="ml-auto flex items-center gap-2 text-xs text-muted-foreground tabular-nums"
            >
                {{ when(signal.created_at) }} · US$
                {{ signal.cost.toFixed(4) }}
                <ChevronDown
                    class="size-4 shrink-0 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                />
            </span>
        </CollapsibleTrigger>

        <CollapsibleContent class="border-t px-4 pb-4">
            <p v-if="signal.reason" class="mt-3 text-sm text-muted-foreground">
                {{ signal.reason }}
            </p>

            <p v-if="signal.entry_price" class="mt-1 text-sm tabular-nums">
                Entrada {{ money(signal.entry_price, market) }} · stop
                {{ money(signal.stop_price, market) }} · alvo
                {{ money(signal.target_price, market) }}
            </p>

            <ul
                v-if="signal.votes.length"
                class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
            >
                <li
                    v-for="vote in signal.votes"
                    :key="vote.agent"
                    class="rounded-lg bg-muted/40 p-3 text-sm"
                >
                    <p class="font-medium">
                        {{ agentLabels[vote.agent] ?? vote.agent }}:
                        <template v-if="vote.abstained">Absteve-se</template>
                        <template v-else>
                            {{ actionLabels[vote.action] }}
                            {{ Math.round(vote.confidence * 100) }}%
                        </template>
                        <span v-if="vote.veto" class="text-destructive">
                            · veto
                        </span>
                    </p>
                    <p class="text-muted-foreground">
                        {{ vote.reasons.join(' ') }}
                    </p>
                    <ChartReading
                        v-if="vote.analysis && !vote.abstained"
                        :analysis="vote.analysis"
                        :market="market"
                    />
                </li>
            </ul>

            <SignalCalculation
                v-if="signal.calculation"
                :calculation="signal.calculation"
                :reason="signal.reason"
            />

            <div v-if="signal.stage === 'approved'" class="mt-3 flex gap-2">
                <Form
                    v-bind="SignalDecisionController.confirm.form(signal.id)"
                    v-slot="{ processing }"
                    :options="decisionOptions"
                >
                    <Button size="sm" :disabled="processing">
                        Confirmar e executar
                    </Button>
                </Form>
                <Form
                    v-bind="SignalDecisionController.reject.form(signal.id)"
                    v-slot="{ processing }"
                    :options="decisionOptions"
                >
                    <Button size="sm" variant="outline" :disabled="processing">
                        Descartar
                    </Button>
                </Form>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
