<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { echoIsConfigured } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    liquidate as liquidateAction,
    liquidateNow as liquidateNowAction,
} from '@/actions/App/Http/Controllers/MicroOperationsController';
import InputError from '@/components/InputError.vue';
import MicroFocusPairs from '@/components/portfolio/MicroFocusPairs.vue';
import { useMarketPrices } from '@/composables/useMarketPrices';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/trading';
import { micro as microRoute } from '@/routes/portfolios';
import type {
    MicroLiquidationRow,
    MicroOverview,
    MicroRuleOption,
    PortfolioSummary,
} from '@/types';

const props = defineProps<{
    portfolio: PortfolioSummary;
    micro: MicroOverview;
}>();

const toggling = ref<number | null>(null);

// Regra de entrada: o card mostra o último micro:backtest de cada uma.
const chosenRule = ref(props.micro.rule);
watch(
    () => props.micro.rule,
    (rule) => (chosenRule.value = rule),
);
const chosenRuleOption = computed(
    () =>
        props.micro.rules.find((rule) => rule.value === chosenRule.value) ??
        null,
);

function backtestText(rule: MicroRuleOption) {
    const backtest = rule.backtest;

    if (backtest === null) {
        return 'Ainda sem backtest. Rode php artisan micro:backtest --save.';
    }

    const mean =
        backtest.mean_pct === null
            ? 'sem média'
            : `${backtest.mean_pct.toFixed(3).replace('.', ',')}% por operação`;
    const date = new Date(backtest.at).toLocaleDateString('pt-BR');

    return backtest.passed
        ? `Passou no backtest de ${date} (${backtest.days} dias): ${backtest.trades} entradas na validação, ${mean} líquido.`
        : `Não passou no backtest de ${date} (${backtest.days} dias): ${backtest.failures.join('; ')}.`;
}

// Entrada: limitada no preço sugerido (padrão) ou a mercado; a validade só vale para a limitada.
const entryMode = ref(props.micro.entry_mode);
watch(
    () => props.micro.entry_mode,
    (mode) => (entryMode.value = mode),
);

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

// "Liquidar agora": a confirmação mostra o resultado estimado pelo preço ao vivo, inclusive negativo.
const sellingId = ref<number | null>(null);
const selling = ref(false);
const sellingRow = computed(
    () =>
        liquidation.value.find((row) => row.asset_id === sellingId.value) ??
        null,
);

function estimate(row: MicroLiquidationRow) {
    if (row.price === null || row.cost === null || row.qty <= 0) {
        return null;
    }

    const value = row.qty * row.price;
    const net =
        row.qty * (row.price - row.cost) - value * props.micro.sell_fee_rate;

    return { value, net, pct: (net / (row.qty * row.cost)) * 100 };
}

const sellingEstimate = computed(() =>
    sellingRow.value ? estimate(sellingRow.value) : null,
);

function liquidateNow() {
    if (sellingId.value === null) {
        return;
    }

    selling.value = true;

    router.post(
        liquidateNowAction.url(props.portfolio.id),
        { asset_id: sellingId.value },
        {
            preserveScroll: true,
            only: ['portfolio', 'micro', 'positions'],
            onFinish: () => {
                selling.value = false;
                sellingId.value = null;
            },
        },
    );
}

const oddsTone = (pct: number | null) =>
    pct === null ? '' : pct >= 60 ? 'text-up' : pct < 30 ? 'text-down' : '';

const price = (value: number | null) =>
    value === null
        ? 'sem cotação'
        : value.toLocaleString('pt-BR', { maximumSignificantDigits: 6 });

const usdt = (value: number) => money(value, 'crypto');

const pct = (value: number) =>
    value.toLocaleString('pt-BR', { maximumFractionDigits: 2 });

// Perda do dia em USDT que a guarda aceita hoje nesta carteira.
const lossValue = computed(
    () =>
        (props.micro.daily_loss.limit_pct / 100) *
        props.micro.daily_loss.start_equity,
);

const exitLabels: Record<string, string> = {
    target: 'Alvo',
    stop: 'Stop',
    micro_time: 'Tempo limite',
    period_end: 'Fim do período',
    delisting: 'Fim de negociação',
    sold: 'Vendida',
    micro_liquidate: 'Liquidado com lucro',
    micro_manual: 'Liquidado à mão',
    conta: 'Saiu da conta',
};

const time = (iso: string, seconds = false) =>
    new Date(iso).toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        ...(seconds ? { second: '2-digit' } : {}),
    });
const dayTime = (iso: string) =>
    new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
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

        <div class="grid grid-cols-2 gap-3 @xl:grid-cols-4">
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
            v-if="micro.bnb_fees"
            class="rounded-lg border p-3 text-sm"
            :class="
                ['ativo', 'desconhecido'].includes(micro.bnb_fees.state)
                    ? 'text-muted-foreground'
                    : 'border-destructive/40 bg-destructive/10'
            "
            data-test="micro-bnb-fees"
        >
            {{ micro.bnb_fees.message }}
        </p>

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
            class="grid gap-4 @sm:grid-cols-2 @3xl:grid-cols-5"
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
                <Label for="micro_daily_target_pct"
                    >Meta de lucro do dia (% do saldo)</Label
                >
                <Input
                    id="micro_daily_target_pct"
                    name="micro_daily_target_pct"
                    type="number"
                    step="0.1"
                    min="0.01"
                    max="100"
                    placeholder="Sem meta"
                    :default-value="micro.daily_target_pct ?? ''"
                />
                <p
                    v-if="micro.daily_target_value !== null"
                    class="text-xs text-muted-foreground"
                >
                    Hoje: {{ usdt(micro.daily_target_value) }} USDT sobre o
                    saldo de {{ usdt(micro.daily_loss.start_equity) }} no início
                    do dia.
                </p>
                <InputError :message="errors.micro_daily_target_pct" />
            </div>
            <div class="grid gap-2" data-test="micro-daily-loss">
                <Label for="daily_loss_pct"
                    >Perda máxima do dia (% do saldo)</Label
                >
                <Input
                    id="daily_loss_pct"
                    name="daily_loss_pct"
                    type="number"
                    step="0.1"
                    min="0.1"
                    :max="micro.daily_loss.max_pct"
                    :placeholder="`${pct(micro.daily_loss.default_pct)} (padrão)`"
                    :default-value="micro.daily_loss_pct ?? ''"
                />
                <p class="text-xs text-muted-foreground">
                    É o limite da guarda de risco desta carteira: perdendo
                    {{ pct(micro.daily_loss.limit_pct) }}% (≈
                    {{ usdt(lossValue) }} USDT) desde o início do dia, nenhuma
                    compra sai até a virada, às 00:00 UTC. Hoje:
                    {{ pct(Math.max(micro.daily_loss.today_pct, 0)) }}% de
                    perda. Máximo {{ micro.daily_loss.max_pct }}%.
                </p>
                <InputError :message="errors.daily_loss_pct" />
            </div>

            <div
                class="grid gap-2 @sm:col-span-2 @3xl:col-span-5"
                data-test="micro-rule"
            >
                <Label for="micro_rule">Regra de entrada</Label>
                <select
                    id="micro_rule"
                    v-model="chosenRule"
                    name="micro_rule"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs @sm:max-w-sm"
                >
                    <option
                        v-for="rule in micro.rules"
                        :key="rule.value"
                        :value="rule.value"
                    >
                        {{ rule.label }}
                    </option>
                </select>
                <p
                    v-if="chosenRuleOption"
                    class="text-xs text-muted-foreground"
                    data-test="micro-rule-backtest"
                >
                    <template v-if="chosenRuleOption.value === 'scalp'">
                        Leitura das velas de 1 minuto, com alvo pela margem
                        líquida acima.
                    </template>
                    <template
                        v-else-if="chosenRuleOption.value === 'scalp_uptrend'"
                    >
                        A regra atual, com alvo pela margem líquida acima, e só
                        entra se o par estiver acima do preço de 24 horas e de 4
                        horas atrás. Na carteira real só pode ser escolhida
                        depois de passar no backtest.
                    </template>
                    <template
                        v-else-if="chosenRuleOption.value === 'scalp_filtered'"
                    >
                        A regra atual com a alta em 24 e 4 horas, e só entra se
                        o RSI de 1 minuto estiver abaixo de 70 (preço não
                        esticado) e o volume dos últimos 5 minutos estiver acima
                        da média. Na carteira real só pode ser escolhida depois
                        de passar no backtest.
                    </template>
                    <template v-else-if="chosenRuleOption.value === 'breakout'">
                        Espera 30 minutos de velas pequenas e compra quando uma
                        vela fecha acima da máxima com volume alto; alvo pela
                        altura da faixa, stop abaixo do meio dela, até 1 hora (a
                        margem líquida acima não vale aqui). Na carteira real só
                        pode ser escolhida depois de passar no backtest.
                    </template>
                    <template
                        v-else-if="
                            chosenRuleOption.value === 'breakout_pressure'
                        "
                    >
                        Compra antes do rompimento: faixa de 30 minutos
                        comprimida, mínimas subindo, máxima testada várias vezes
                        e volume crescendo; stop abaixo da última mínima, até 1
                        hora (a margem líquida acima não vale aqui). Na carteira
                        real só pode ser escolhida depois de passar no backtest.
                    </template>
                    <template v-else>
                        Compra no recuo de uma alta de 1 hora; alvo e stop pela
                        volatilidade de 15 minutos (a margem líquida acima não
                        vale aqui) e até 4 horas por ciclo. Na carteira real só
                        pode ser escolhida depois de passar no backtest.
                    </template>
                    {{ backtestText(chosenRuleOption) }}
                </p>
                <InputError :message="errors.micro_rule" />
            </div>
            <div class="grid gap-2">
                <Label for="micro_entry_mode">Entrada</Label>
                <select
                    id="micro_entry_mode"
                    v-model="entryMode"
                    name="micro_entry_mode"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    data-test="micro-entry-mode"
                >
                    <option value="limit">Limitada no preço sugerido</option>
                    <option value="market">A mercado</option>
                </select>
                <p class="text-xs text-muted-foreground">
                    <template v-if="entryMode === 'limit'">
                        O motor calcula o preço de compra e espera o recuo até
                        ele. Se não executar no prazo, cancela e reavalia.
                    </template>
                    <template v-else>
                        Compra na hora pelo preço do momento, que pode ter
                        subido desde a leitura do gráfico.
                    </template>
                </p>
                <InputError :message="errors.micro_entry_mode" />
            </div>
            <div v-if="entryMode === 'limit'" class="grid gap-2">
                <Label for="micro_limit_seconds">
                    Validade da compra limitada (segundos)
                </Label>
                <Input
                    id="micro_limit_seconds"
                    name="micro_limit_seconds"
                    type="number"
                    step="1"
                    :min="micro.limit_seconds_range[0]"
                    :max="micro.limit_seconds_range[1]"
                    :default-value="micro.limit_seconds"
                    data-test="micro-limit-seconds"
                />
                <p class="text-xs text-muted-foreground tabular-nums">
                    De {{ micro.limit_seconds_range[0] }} a
                    {{ micro.limit_seconds_range[1] }} s.
                    <template v-if="micro.today.limits_expired">
                        Hoje {{ micro.today.limits_expired }} venceram sem
                        executar.
                    </template>
                </p>
                <InputError :message="errors.micro_limit_seconds" />
            </div>

            <div
                class="flex flex-wrap items-center gap-4 @sm:col-span-2 @3xl:col-span-5"
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
            v-else-if="micro.enabled && micro.last_run && micro.realtime"
            class="text-sm text-muted-foreground"
            data-test="micro-realtime"
        >
            Motor em tempo real: stop e alvo conferidos a cada segundo, entradas
            a cada poucos segundos. Última avaliação às
            {{ time(micro.last_run, true) }}.
        </p>
        <p
            v-else-if="micro.enabled && micro.last_run"
            class="text-sm text-muted-foreground"
            data-test="micro-minute"
        >
            Última execução do motor às {{ time(micro.last_run) }}, na rodada
            por minuto. O motor em tempo real (<code>mymoney-micro</code>) não
            está rodando.
        </p>

        <p v-if="micro.last_block" class="text-sm text-muted-foreground">
            Último bloqueio: {{ micro.last_block }}
        </p>

        <MicroFocusPairs v-if="micro.focus.length" :focus="micro.focus" />

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
                            v-else-if="row.qty > 0 && row.sell_skip"
                            class="text-muted-foreground"
                            :data-test="`micro-sell-skip-${row.symbol}`"
                        >
                            Sem venda registrada: {{ row.sell_skip }}
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
                        <span
                            v-if="row.qty > 0 && row.below_minimum"
                            class="text-amber-400"
                            :data-test="`micro-below-minimum-${row.symbol}`"
                        >
                            {{ row.below_minimum }}
                        </span>
                        <span
                            v-if="row.qty > 0 && row.odds"
                            class="flex flex-col gap-0.5"
                            :data-test="`micro-odds-${row.symbol}`"
                        >
                            <span class="tabular-nums">
                                Chance de alcançar o alvo de
                                {{ price(row.odds.target) }} em
                                {{ row.odds.minutes }} min:
                                <span
                                    class="font-medium"
                                    :class="oddsTone(row.odds.pct)"
                                    >{{
                                        row.odds.pct === null
                                            ? 'sem dados'
                                            : `${row.odds.pct}%`
                                    }}</span
                                >
                                <span
                                    v-if="row.odds.distance_pct > 0"
                                    class="text-muted-foreground"
                                >
                                    · faltam
                                    {{
                                        row.odds.distance_pct.toLocaleString(
                                            'pt-BR',
                                            { maximumFractionDigits: 2 },
                                        )
                                    }}%<template
                                        v-if="row.odds.distance_atr !== null"
                                    >
                                        ({{
                                            row.odds.distance_atr.toLocaleString(
                                                'pt-BR',
                                                { maximumFractionDigits: 1 },
                                            )
                                        }}
                                        ATR de 1 min)</template
                                    >
                                </span>
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ row.odds.reason }}
                            </span>
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            :variant="row.marked ? 'outline' : 'secondary'"
                            :disabled="toggling === row.asset_id"
                            :data-test="`micro-liquidate-${row.symbol}`"
                            @click="
                                toggleLiquidation(row.asset_id, !row.marked)
                            "
                        >
                            {{
                                row.marked
                                    ? 'Voltar a comprar'
                                    : 'Liquidar com lucro'
                            }}
                        </Button>
                        <Button
                            v-if="row.qty > 0"
                            type="button"
                            size="sm"
                            variant="outline"
                            class="text-destructive"
                            :disabled="selling"
                            :data-test="`micro-liquidate-now-${row.symbol}`"
                            @click="sellingId = row.asset_id"
                        >
                            Liquidar agora
                        </Button>
                    </div>
                </li>
            </ul>
        </div>

        <Dialog
            :open="sellingId !== null"
            @update:open="(open) => !open && !selling && (sellingId = null)"
        >
            <DialogContent v-if="sellingRow">
                <DialogHeader class="space-y-3">
                    <DialogTitle>
                        Vender {{ sellingRow.symbol }} agora a mercado?
                    </DialogTitle>
                    <DialogDescription>
                        A venda registrada na corretora é cancelada e toda a
                        posição é vendida pelo preço de agora, mesmo com
                        prejuízo. A guarda de risco e o "Parar tudo" continuam
                        valendo.
                    </DialogDescription>
                </DialogHeader>

                <dl
                    class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm tabular-nums"
                    data-test="micro-liquidate-now-estimate"
                >
                    <dt class="text-muted-foreground">Quantidade</dt>
                    <dd>{{ sellingRow.qty }}</dd>
                    <dt class="text-muted-foreground">Custo</dt>
                    <dd>{{ price(sellingRow.cost) }}</dd>
                    <dt class="text-muted-foreground">Preço agora</dt>
                    <dd>{{ price(sellingRow.price) }}</dd>
                    <template v-if="sellingEstimate">
                        <dt class="text-muted-foreground">Recebe cerca de</dt>
                        <dd>
                            {{ money(sellingEstimate.value, portfolio.market) }}
                            USDT
                        </dd>
                        <dt class="text-muted-foreground">
                            Resultado estimado
                        </dt>
                        <dd
                            class="font-medium"
                            :class="
                                sellingEstimate.net >= 0
                                    ? 'text-up'
                                    : 'text-down'
                            "
                        >
                            {{ money(sellingEstimate.net, portfolio.market) }}
                            USDT ({{
                                sellingEstimate.pct.toLocaleString('pt-BR', {
                                    maximumFractionDigits: 2,
                                })
                            }}%)
                        </dd>
                    </template>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Estimativa com a taxa de venda; o preço executado pode
                    variar um pouco.
                </p>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" :disabled="selling">
                            Cancelar
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="selling"
                        data-test="micro-liquidate-now-confirm"
                        @click="liquidateNow"
                    >
                        Vender agora
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

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
                            <template v-if="cycle.status === 'pending'">
                                <span class="text-muted-foreground"
                                    >limitada a {{ cycle.entry_price }}</span
                                >
                            </template>
                            <template v-else>
                                {{ cycle.entry_price }} ·
                                {{ time(cycle.opened_at) }}
                                <span
                                    v-if="cycle.suggested_price"
                                    class="block text-xs text-muted-foreground"
                                    :data-test="`micro-cycle-${cycle.id}-suggested`"
                                >
                                    sugerido {{ cycle.suggested_price }}
                                    {{
                                        cycle.entry_mode === 'market'
                                            ? '(a mercado)'
                                            : ''
                                    }}
                                </span>
                            </template>
                        </td>
                        <td class="py-1 pr-3">
                            <template v-if="cycle.status === 'pending'">
                                <span class="text-muted-foreground">
                                    esperando o preço até
                                    {{ time(cycle.expires_at!, true) }}
                                </span>
                            </template>
                            <template v-else-if="cycle.exit_price">
                                {{ cycle.exit_price }} ·
                                {{ time(cycle.closed_at!) }}
                                <span
                                    v-if="
                                        cycle.exit_source === 'cotacao' ||
                                        cycle.exit_source === 'binance'
                                    "
                                    class="block text-xs text-muted-foreground"
                                    :data-test="`micro-cycle-${cycle.id}-exit-source`"
                                >
                                    {{
                                        cycle.exit_source === 'cotacao'
                                            ? 'estimado pela cotação: a moeda saiu da conta sem venda no app'
                                            : 'vendido na Binance fora do app'
                                    }}
                                </span>
                            </template>
                            <template v-else-if="cycle.status === 'closed'">
                                sem venda no app ·
                                {{ time(cycle.closed_at!) }}
                            </template>
                            <template v-else-if="cycle.stuck_since">
                                <span
                                    class="text-down"
                                    :data-test="`micro-cycle-${cycle.id}-stuck`"
                                    >preso abaixo do mínimo de venda desde
                                    {{ dayTime(cycle.stuck_since) }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >o app não consegue vender; sai pelo alvo ou
                                    pela conversão de saldo pequeno na
                                    Binance</span
                                >
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
                                (cycle.status === 'open'
                                    ? 'aberto'
                                    : cycle.status === 'pending'
                                      ? 'esperando'
                                      : '—')
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
