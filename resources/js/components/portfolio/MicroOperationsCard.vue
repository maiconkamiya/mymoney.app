<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { echoIsConfigured } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { liquidate as liquidateAction } from '@/actions/App/Http/Controllers/MicroOperationsController';
import InputError from '@/components/InputError.vue';
import { useMarketPrices } from '@/composables/useMarketPrices';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/trading';
import { micro as microRoute } from '@/routes/portfolios';
import type { MicroOverview, PortfolioSummary } from '@/types';

const props = defineProps<{
    portfolio: PortfolioSummary;
    micro: MicroOverview;
}>();

const toggling = ref<number | null>(null);

// O motor avisa a cada minuto; se os avisos param, o relógio da tela mostra o alerta sem F5.
const clock = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;
onMounted(
    () => (ticker = setInterval(() => (clock.value = Date.now()), 30_000)),
);
onBeforeUnmount(() => clearInterval(ticker));

// Conta pelo relógio do navegador desde a última foto recebida (o do WSL pode estar adiantado).
const receivedAt = ref(Date.now());
watch(
    () => props.micro,
    () => (receivedAt.value = Date.now()),
);

const schedulerStale = computed(
    () =>
        props.micro.scheduler_stale ||
        (echoIsConfigured() &&
            props.micro.enabled &&
            clock.value - receivedAt.value > 3 * 60_000),
);

// O preço "agora" do liquidar com lucro anda com o canal `mercado`, sem esperar o minuto do motor.
const marketPrices = useMarketPrices();
const liquidation = computed(() =>
    props.micro.liquidation.map((row) => ({
        ...row,
        price:
            row.qty > 0 ? (marketPrices[row.symbol] ?? row.price) : row.price,
    })),
);

function toggleLiquidation(assetId: number, liquidate: boolean) {
    toggling.value = assetId;

    router.put(
        liquidateAction.url(props.portfolio.id),
        { asset_id: assetId, liquidate },
        {
            preserveScroll: true,
            only: ['portfolio', 'micro'],
            onFinish: () => (toggling.value = null),
        },
    );
}

const price = (value: number | null) =>
    value === null
        ? 'sem cotação'
        : value.toLocaleString('pt-BR', { maximumSignificantDigits: 6 });

const exitLabels: Record<string, string> = {
    target: 'Alvo',
    stop: 'Stop',
    micro_time: 'Tempo limite',
    period_end: 'Fim do período',
    sold: 'Vendida',
    micro_liquidate: 'Liquidado com lucro',
    conta: 'Saiu da conta',
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

        <p
            v-if="micro.margin_warning"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
            data-test="micro-margin-warning"
        >
            {{ micro.margin_warning }}
        </p>

        <Form
            v-bind="microRoute.form(portfolio.id)"
            v-slot="{ errors, processing }"
            :options="{ preserveScroll: true, only: ['portfolio', 'micro'] }"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
        >
            <div class="grid gap-2">
                <Label for="micro_order_value">
                    Valor por operação em USDT (mínimo
                    {{ micro.min_order_value }})
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
                <Label for="micro_wallet_pct">
                    Aporte da carteira (% do saldo em USDT)
                </Label>
                <Input
                    id="micro_wallet_pct"
                    name="micro_wallet_pct"
                    type="number"
                    step="1"
                    min="1"
                    max="100"
                    :default-value="micro.wallet_pct"
                    data-test="micro-wallet-pct"
                />
                <p class="text-xs text-muted-foreground tabular-nums">
                    Em uso {{ micro.wallet_budget.used.toFixed(2) }} de
                    {{ micro.wallet_budget.limit.toFixed(2) }} USDT. Cada venda
                    devolve o saldo e libera novas compras.
                </p>
                <InputError :message="errors.micro_wallet_pct" />
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
                <p class="text-xs text-muted-foreground">
                    Conta só as compras. A venda no alvo é registrada junto com
                    cada compra.
                </p>
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
                <Label for="micro_daily_target"
                    >Meta de lucro do dia (USDT)</Label
                >
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
                <Label for="micro_daily_loss_limit"
                    >Perda máxima do dia (USDT)</Label
                >
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

        <p
            v-if="schedulerStale"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
            data-test="micro-scheduler-stale"
        >
            O motor não rodou nos últimos 3 minutos. Confira se o serviço
            <code>mymoney-schedule</code> está de pé (<code
                >systemctl --user status mymoney-schedule</code
            >).
        </p>
        <p
            v-else-if="micro.enabled && micro.last_run"
            class="text-sm text-muted-foreground"
        >
            Última execução do motor às {{ time(micro.last_run) }}.
        </p>

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
                        faixa média {{ row.atr_pct.toFixed(3) }}% por minuto ·
                        alcance {{ row.reach_pct.toFixed(2) }}% em 60 min
                    </span>
                    <span class="text-muted-foreground">
                        {{
                            row.blocked
                                ? 'Entrada liberada, mas bloqueada: ' +
                                  row.blocked
                                : row.entry
                                  ? 'Entrada liberada'
                                  : row.reason
                        }}
                    </span>
                </li>
            </ul>
        </div>

        <ul
            v-if="micro.skipped.length"
            class="grid gap-1 text-sm text-muted-foreground"
        >
            <li v-for="row in micro.skipped" :key="row.symbol">
                <span class="font-medium text-foreground">{{
                    row.symbol
                }}</span>
                sem leitura: {{ row.reason }}
            </li>
        </ul>

        <div
            v-if="micro.liquidation.length"
            class="flex flex-col gap-2"
            data-test="micro-liquidation"
        >
            <h4 class="text-sm font-medium">Liquidar com lucro</h4>
            <p class="text-sm text-muted-foreground">
                Marque um ativo para vender a posição assim que o preço cobrir o
                custo de compra e o custo da ida e volta, e para o modo não
                comprar mais esse ativo. Vale mesmo com as micro operações
                desligadas. Desmarque para voltar a comprar.
            </p>
            <p
                v-if="micro.liquidation_blocked"
                class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
            >
                {{ micro.liquidation_blocked }}
            </p>
            <ul class="grid gap-2 text-sm">
                <li
                    v-for="row in liquidation"
                    :key="row.asset_id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
                    :data-test="`micro-liquidation-${row.symbol}`"
                >
                    <div class="flex flex-col gap-0.5">
                        <span class="flex items-center gap-2 font-medium">
                            {{ row.symbol }}
                            <Badge v-if="row.marked" variant="secondary">
                                Liquidar com lucro
                            </Badge>
                        </span>
                        <span
                            v-if="row.qty > 0"
                            class="text-muted-foreground tabular-nums"
                        >
                            {{ row.qty }} na carteira · custo
                            {{ price(row.cost) }} · vende a partir de
                            {{ price(row.sell_from) }} · agora
                            <span
                                :class="
                                    row.price !== null &&
                                    row.sell_from !== null &&
                                    row.price >= row.sell_from
                                        ? 'text-up'
                                        : ''
                                "
                                >{{ price(row.price) }}</span
                            >
                        </span>
                        <span v-else class="text-muted-foreground">
                            Sem posição aberta. O modo não compra este ativo.
                        </span>
                        <span
                            v-if="row.qty > 0 && row.resting_sell_price"
                            class="text-muted-foreground tabular-nums"
                            :data-test="`micro-sell-${row.symbol}`"
                        >
                            Venda registrada na corretora a
                            {{ price(row.resting_sell_price) }}
                        </span>
                        <span
                            v-else-if="row.qty > 0 && row.sell_failure"
                            class="text-down"
                            :data-test="`micro-sell-failure-${row.symbol}`"
                        >
                            Venda não registrada: {{ row.sell_failure.reason }}
                            Nova tentativa às
                            {{ time(row.sell_failure.retry_at) }}.
                        </span>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        :variant="row.marked ? 'outline' : 'secondary'"
                        :disabled="toggling === row.asset_id"
                        :data-test="`micro-liquidate-${row.symbol}`"
                        @click="toggleLiquidation(row.asset_id, !row.marked)"
                    >
                        {{
                            row.marked
                                ? 'Voltar a comprar'
                                : 'Liquidar com lucro'
                        }}
                    </Button>
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
                            <template v-else-if="cycle.status === 'closed'">
                                sem venda no app ·
                                {{ time(cycle.closed_at!) }}
                            </template>
                            <template v-else-if="cycle.resting_sell_price">
                                venda registrada a
                                {{ cycle.resting_sell_price }}
                            </template>
                            <template v-else-if="cycle.sell_failure">
                                <span class="text-down"
                                    >venda recusada:
                                    {{ cycle.sell_failure }}</span
                                >
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
                            {{
                                cycle.net_pnl ??
                                (cycle.status === 'open' ? 'aberto' : '—')
                            }}
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
