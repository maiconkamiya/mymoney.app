<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/trading';
import { micro as microRoute } from '@/routes/portfolios';
import type { MicroOverview, PortfolioSummary } from '@/types';

defineProps<{
    portfolio: PortfolioSummary;
    micro: MicroOverview;
}>();

const exitLabels: Record<string, string> = {
    target: 'Alvo',
    stop: 'Stop',
    micro_time: 'Tempo limite',
    period_end: 'Fim do período',
    sold: 'Vendida',
};

const time = (iso: string) =>
    new Date(iso).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border bg-card p-4"
        data-test="micro-operations-card"
    >
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="font-medium">Micro operações</h3>
            <Badge v-if="micro.enabled && micro.state.code === 'active'">
                {{ micro.state.label }}
            </Badge>
            <Badge v-else variant="secondary">{{ micro.state.label }}</Badge>
        </div>

        <p class="text-sm text-muted-foreground">
            Muitas compras e vendas pequenas nos pares mais voláteis, com lucro
            líquido pequeno por operação. Entra só com tendência de alta e preço
            perto do suporte, com espaço até a resistência; sai no alvo, no stop
            ou ao fim do tempo limite. Não consulta modelo de IA: sem custo por
            operação. A guarda de risco, os limites reais e o "Parar tudo" valem
            sempre.
        </p>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-3">
                <p class="text-xs text-muted-foreground">
                    Custo da ida e volta
                </p>
                <p class="text-lg font-semibold tabular-nums">
                    {{ micro.round_trip_cost_pct.toFixed(2) }}%
                </p>
            </div>
            <div class="rounded-xl border p-3">
                <p class="text-xs text-muted-foreground">
                    Margem bruta do alvo
                </p>
                <p class="text-lg font-semibold tabular-nums">
                    {{ micro.gross_margin_pct.toFixed(2) }}%
                </p>
            </div>
            <div class="rounded-xl border p-3">
                <p class="text-xs text-muted-foreground">Hoje</p>
                <p class="text-lg font-semibold tabular-nums">
                    {{ micro.today.opened
                    }}{{
                        micro.daily_operations
                            ? ` de ${micro.daily_operations}`
                            : ''
                    }}
                    operações · {{ micro.today.wins }} no lucro
                </p>
            </div>
            <div class="rounded-xl border p-3">
                <p class="text-xs text-muted-foreground">Lucro líquido hoje</p>
                <p
                    class="text-lg font-semibold tabular-nums"
                    :class="micro.today.net_pnl < 0 ? 'text-down' : 'text-up'"
                >
                    {{ money(micro.today.net_pnl, portfolio.market) }}
                </p>
            </div>
        </div>

        <Form
            v-bind="microRoute.form(portfolio.id)"
            v-slot="{ errors, processing }"
            :options="{ preserveScroll: true }"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
        >
            <div class="grid gap-2">
                <Label for="micro_order_value">
                    Valor por operação (mínimo {{ micro.min_order_value }})
                </Label>
                <Input
                    id="micro_order_value"
                    name="micro_order_value"
                    type="number"
                    step="0.01"
                    :min="micro.min_order_value"
                    :default-value="micro.order_value"
                />
                <InputError :message="errors.micro_order_value" />
            </div>
            <div class="grid gap-2">
                <Label for="micro_daily_operations">Operações por dia</Label>
                <Input
                    id="micro_daily_operations"
                    name="micro_daily_operations"
                    type="number"
                    step="1"
                    min="1"
                    placeholder="Sem limite"
                    :default-value="micro.daily_operations ?? ''"
                />
                <InputError :message="errors.micro_daily_operations" />
            </div>
            <div class="grid gap-2">
                <Label for="micro_net_margin_pct">Margem líquida (%)</Label>
                <Input
                    id="micro_net_margin_pct"
                    name="micro_net_margin_pct"
                    type="number"
                    step="0.01"
                    min="0.05"
                    :default-value="micro.net_margin_pct"
                />
                <InputError :message="errors.micro_net_margin_pct" />
            </div>
            <div class="grid gap-2">
                <Label for="micro_daily_target">Meta de lucro do dia</Label>
                <Input
                    id="micro_daily_target"
                    name="micro_daily_target"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Sem meta"
                    :default-value="micro.daily_target ?? ''"
                />
                <InputError :message="errors.micro_daily_target" />
            </div>
            <div class="grid gap-2">
                <Label for="micro_daily_loss_limit">Perda máxima do dia</Label>
                <Input
                    id="micro_daily_loss_limit"
                    name="micro_daily_loss_limit"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Sem limite"
                    :default-value="micro.daily_loss_limit ?? ''"
                />
                <InputError :message="errors.micro_daily_loss_limit" />
            </div>

            <div
                class="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-5"
            >
                <input type="hidden" name="micro_enabled" value="0" />
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="micro_enabled"
                        value="1"
                        :checked="micro.enabled"
                        data-test="micro-enabled"
                    />
                    Operar em micro operações nesta carteira
                </label>
                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="micro-save"
                >
                    Salvar
                </Button>
                <InputError :message="errors.micro_enabled" />
            </div>
        </Form>

        <p v-if="micro.last_block" class="text-sm text-muted-foreground">
            Último bloqueio: {{ micro.last_block }}
        </p>

        <div v-if="micro.focus.length" class="flex flex-col gap-2">
            <h4 class="text-sm font-medium">Pares em foco (mais voláteis)</h4>
            <ul class="grid gap-1 text-sm">
                <li
                    v-for="row in micro.focus"
                    :key="row.symbol"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="font-medium">{{ row.symbol }}</span>
                    <span class="text-muted-foreground tabular-nums">
                        faixa média {{ row.atr_pct.toFixed(3) }}% por minuto
                    </span>
                    <span class="text-muted-foreground">
                        {{ row.entry ? 'Entrada liberada' : row.reason }}
                    </span>
                </li>
            </ul>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-test="micro-cycles">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-1 pr-3 font-normal">Par</th>
                        <th class="py-1 pr-3 font-normal">Entrada</th>
                        <th class="py-1 pr-3 font-normal">Saída</th>
                        <th class="py-1 pr-3 font-normal">Taxas</th>
                        <th class="py-1 pr-3 font-normal">Líquido</th>
                        <th class="py-1 font-normal">Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!micro.cycles.length">
                        <td colspan="6" class="py-3 text-muted-foreground">
                            Nenhum ciclo ainda.
                        </td>
                    </tr>
                    <tr
                        v-for="cycle in micro.cycles"
                        :key="cycle.id"
                        class="border-t tabular-nums"
                    >
                        <td class="py-1 pr-3 font-medium">
                            {{ cycle.symbol }}
                        </td>
                        <td class="py-1 pr-3">
                            {{ cycle.entry_price }} ·
                            {{ time(cycle.opened_at) }}
                        </td>
                        <td class="py-1 pr-3">
                            <template v-if="cycle.exit_price">
                                {{ cycle.exit_price }} ·
                                {{ time(cycle.closed_at!) }}
                            </template>
                            <template v-else>
                                alvo {{ cycle.target_price.toFixed(4) }}
                            </template>
                        </td>
                        <td class="py-1 pr-3">{{ cycle.fees }}</td>
                        <td
                            class="py-1 pr-3"
                            :class="
                                cycle.net_pnl === null
                                    ? ''
                                    : cycle.net_pnl < 0
                                      ? 'text-down'
                                      : 'text-up'
                            "
                        >
                            {{ cycle.net_pnl ?? 'aberto' }}
                        </td>
                        <td class="py-1">
                            {{
                                cycle.exit_reason
                                    ? (exitLabels[cycle.exit_reason] ??
                                      cycle.exit_reason)
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
