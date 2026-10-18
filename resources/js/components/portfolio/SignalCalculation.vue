<script setup lang="ts">
import { computed } from 'vue';
import { actionLabels, agentLabels } from '@/lib/trading';
import type { SignalCalculation } from '@/types';

const props = defineProps<{
    calculation: SignalCalculation;
    reason: string | null;
}>();

const valid = computed(() =>
    props.calculation.votes.filter((v) => !v.abstained),
);
const abstained = computed(() =>
    props.calculation.votes.filter((v) => v.abstained),
);

const signed = (value: number) => `${value > 0 ? '+' : ''}${value.toFixed(3)}`;

const decimal = (value: number) =>
    value.toLocaleString('pt-BR', { maximumFractionDigits: 2 });

const price = (value: number) =>
    value.toLocaleString('pt-BR', { maximumFractionDigits: 8 });

const ceilingSources: Record<string, string> = {
    resistencia: 'resistência',
    maxima_1h: 'máxima de 1h',
    maxima_4h: 'máxima de 4h',
    atr: 'preço + 2 ATR',
};
</script>

<template>
    <details class="mt-3 rounded-lg bg-muted/30 p-3 text-sm">
        <summary class="cursor-pointer font-medium">
            Como o score foi calculado
        </summary>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left tabular-nums">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th class="pb-1 font-normal">Agente</th>
                        <th class="pb-1 font-normal">Voto</th>
                        <th class="pb-1 text-right font-normal">Confiança</th>
                        <th class="pb-1 text-right font-normal">Peso</th>
                        <th class="pb-1 text-right font-normal">
                            Contribuição
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="vote in valid" :key="vote.agent">
                        <td class="py-0.5">
                            {{ agentLabels[vote.agent] ?? vote.agent }}
                        </td>
                        <td>{{ actionLabels[vote.action] }}</td>
                        <td class="text-right">
                            {{ Math.round(vote.confidence * 100) }}%
                        </td>
                        <td class="text-right">
                            {{ vote.weight?.toFixed(2) }}
                        </td>
                        <td
                            class="text-right"
                            :class="
                                (vote.contribution ?? 0) > 0
                                    ? 'text-up'
                                    : (vote.contribution ?? 0) < 0
                                      ? 'text-down'
                                      : ''
                            "
                        >
                            {{ signed(vote.contribution ?? 0) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ul v-if="abstained.length" class="mt-2 text-muted-foreground">
            <li v-for="vote in abstained" :key="vote.agent">
                {{ agentLabels[vote.agent] ?? vote.agent }} absteve-se (fora da
                conta){{ vote.reason ? `: ${vote.reason}` : '' }}
            </li>
        </ul>

        <dl class="mt-3 grid gap-x-4 gap-y-1 sm:grid-cols-2">
            <dt class="text-muted-foreground">Votos válidos</dt>
            <dd>
                {{ calculation.valid_votes }} (mínimo
                {{ calculation.min_voters }})
            </dd>
            <dt class="text-muted-foreground">Soma dos pesos</dt>
            <dd>{{ calculation.weight_sum.toFixed(2) }}</dd>
            <dt class="text-muted-foreground">
                Score (soma das contribuições)
            </dt>
            <dd>{{ signed(calculation.score) }}</dd>
            <dt class="text-muted-foreground">Limite de aprovação</dt>
            <dd>{{ calculation.threshold.toFixed(2) }} em módulo</dd>
            <dt class="text-muted-foreground">Concordância</dt>
            <dd>
                {{ calculation.agreeing }} na mesma direção (mínimo
                {{ calculation.quorum }})
            </dd>
        </dl>

        <template v-if="calculation.late_entry">
            <p class="mt-3 font-medium">Entrada tardia</p>
            <dl
                class="mt-1 grid gap-x-4 gap-y-1 tabular-nums sm:grid-cols-2"
                data-testid="late-entry"
            >
                <dt class="text-muted-foreground">Teto provável</dt>
                <dd>
                    {{ price(calculation.late_entry.ceiling) }}
                    <template v-if="calculation.late_entry.ceiling_source">
                        ({{
                            ceilingSources[
                                calculation.late_entry.ceiling_source
                            ] ?? calculation.late_entry.ceiling_source
                        }})
                    </template>
                </dd>
                <template v-if="calculation.late_entry.room_pct !== null">
                    <dt class="text-muted-foreground">Folga até o teto</dt>
                    <dd>{{ decimal(calculation.late_entry.room_pct) }}%</dd>
                </template>
                <dt class="text-muted-foreground">Ganho até o teto / risco</dt>
                <dd
                    :class="
                        calculation.late_entry.reward_risk <
                        calculation.late_entry.min_reward_risk
                            ? 'text-down'
                            : ''
                    "
                >
                    {{ decimal(calculation.late_entry.reward_risk) }} (mínimo
                    {{ decimal(calculation.late_entry.min_reward_risk) }})
                </dd>
                <template
                    v-if="calculation.late_entry.ema21_distance_atr !== null"
                >
                    <dt class="text-muted-foreground">Acima da média de 21</dt>
                    <dd
                        :class="
                            calculation.late_entry.ema21_distance_atr >
                            calculation.late_entry.max_ema21_atr
                                ? 'text-down'
                                : ''
                        "
                    >
                        {{ decimal(calculation.late_entry.ema21_distance_atr) }}
                        ATR (máximo
                        {{ decimal(calculation.late_entry.max_ema21_atr) }})
                    </dd>
                </template>
                <template
                    v-if="calculation.late_entry.move_from_low_atr !== null"
                >
                    <dt class="text-muted-foreground">
                        Subida desde o último fundo
                    </dt>
                    <dd>
                        {{ decimal(calculation.late_entry.move_from_low_atr) }}
                        ATR
                    </dd>
                </template>
                <template v-if="calculation.late_entry.volume_fading !== null">
                    <dt class="text-muted-foreground">Volume na subida</dt>
                    <dd>
                        {{
                            calculation.late_entry.volume_fading
                                ? 'caindo'
                                : 'sustentado'
                        }}
                    </dd>
                </template>
            </dl>
        </template>

        <p v-if="reason" class="mt-3 font-medium">Resultado: {{ reason }}</p>
    </details>
</template>
