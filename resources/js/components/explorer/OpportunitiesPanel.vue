<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { swarm, track } from '@/routes/opportunities';
import { explorer as explorerRoute } from '@/routes/portfolios';
import type { ExplorerData, Opportunity } from '@/types';

const props = defineProps<{
    explorer: ExplorerData;
}>();

/** A varredura roda a cada N minutos; a lista confere a cada minuto sem recarregar a página. */
const REFRESH_MS = 60_000;

const portfolioId = ref<number | null>(
    props.explorer.portfolios.find((p) => p.running)?.id ??
        props.explorer.portfolios[0]?.id ??
        null,
);
const busy = ref<string | null>(null);
const errors = ref<Record<string, string>>({});
const now = ref(Date.now());

let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();

        if (document.visibilityState === 'visible' && busy.value === null) {
            router.reload({ only: ['explorer'] });
        }
    }, REFRESH_MS);
});

onBeforeUnmount(() => clearInterval(timer));

watch(
    () => props.explorer.portfolios,
    (portfolios) => {
        if (!portfolios.some((p) => p.id === portfolioId.value)) {
            portfolioId.value = portfolios[0]?.id ?? null;
        }
    },
);

const selected = computed(() =>
    props.explorer.portfolios.find((p) => p.id === portfolioId.value),
);

const scannedAgo = computed(() => {
    if (!props.explorer.scanned_at) {
        return null;
    }

    const minutes = Math.max(
        0,
        Math.round(
            (now.value - new Date(props.explorer.scanned_at).getTime()) /
                60_000,
        ),
    );

    return minutes === 0 ? 'agora' : `há ${minutes} min`;
});

const number = (value: number, digits = 1) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(value);

const volume = (value: number) =>
    value >= 1_000_000
        ? `${number(value / 1_000_000)} mi`
        : `${number(value / 1_000, 0)} mil`;

const price = (value: number) =>
    new Intl.NumberFormat('pt-BR', { maximumSignificantDigits: 6 }).format(
        value,
    );

function sentRecently(o: Opportunity): boolean {
    return (
        o.swarm_sent_at !== null &&
        now.value - new Date(o.swarm_sent_at).getTime() <
            props.explorer.swarm_cooldown_hours * 3_600_000
    );
}

function post(kind: 'track' | 'swarm', o: Opportunity) {
    if (portfolioId.value === null) {
        return;
    }

    busy.value = `${kind}:${o.id}`;
    errors.value = {};

    router.post(
        (kind === 'track' ? track : swarm).url(o.id),
        { portfolio_id: portfolioId.value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (e) => (errors.value = e),
            onFinish: () => (busy.value = null),
        },
    );
}

function toggleAuto(id: number, enabled: boolean) {
    busy.value = `auto:${id}`;
    errors.value = {};

    router.put(
        explorerRoute.url(id),
        { enabled },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (e) => (errors.value = e),
            onFinish: () => (busy.value = null),
        },
    );
}
</script>

<template>
    <section class="space-y-4">
        <Heading
            variant="small"
            title="Oportunidades"
            :description="`Pares USDT da Binance fora dos acompanhados, ranqueados a cada ${explorer.interval_minutes} min por volume, aceleração, rompimento e distância da média. O explorador nunca compra.`"
        />

        <div
            class="flex flex-col gap-4 rounded-2xl border bg-card p-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="space-y-1 text-sm">
                <p v-if="!explorer.enabled" class="text-muted-foreground">
                    Explorador desligado (EXPLORER_ENABLED).
                </p>
                <p v-else-if="scannedAgo" class="text-muted-foreground">
                    Última varredura {{ scannedAgo }}:
                    <span class="text-foreground tabular-nums">{{
                        explorer.pairs_seen
                    }}</span>
                    pares vistos,
                    <span class="text-foreground tabular-nums">{{
                        explorer.pairs_passed
                    }}</span>
                    passaram nos filtros{{
                        explorer.ai_model ? ', explicados por IA' : ''
                    }}.
                </p>
                <p v-else class="text-muted-foreground">
                    A primeira varredura aparece aqui em até
                    {{ explorer.interval_minutes }} min.
                </p>
                <p v-if="explorer.error" class="text-destructive">
                    {{ explorer.error }}
                </p>
            </div>

            <div
                v-if="explorer.portfolios.length"
                class="flex flex-col gap-3 lg:min-w-[320px]"
            >
                <label class="flex flex-col gap-1.5 text-sm">
                    <span class="text-muted-foreground">Carteira</span>
                    <select
                        v-model="portfolioId"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option
                            v-for="p in explorer.portfolios"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }}{{ p.mode === 'live' ? ' (real)' : ''
                            }}{{ p.running ? '' : ' (parada)' }}
                        </option>
                    </select>
                </label>

                <label
                    v-if="selected"
                    class="flex items-start gap-2 text-sm leading-snug"
                >
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 accent-primary"
                        :checked="selected.auto_swarm"
                        :disabled="busy !== null"
                        @change="
                            toggleAuto(
                                selected.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    <span>
                        Mandar as {{ explorer.swarm_top }} melhores aos agentes
                        sozinho
                        <span class="block text-xs text-muted-foreground">
                            Nota mínima {{ explorer.swarm_min_score }}, no
                            máximo {{ explorer.swarm_max_per_day }} por dia e
                            uma vez a cada {{ explorer.swarm_cooldown_hours }} h
                            por par. Cada envio custa uma rodada dos agentes; a
                            compra, se vier, passa pelo consenso, pela guarda de
                            risco e pelos limites.
                        </span>
                    </span>
                </label>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Crie uma carteira de cripto para acompanhar ou mandar pares aos
                agentes.
            </p>
        </div>

        <InputError
            :message="errors.portfolio_id ?? errors.symbol ?? errors.enabled"
        />

        <div
            v-if="explorer.opportunities.length === 0"
            class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            {{
                scannedAgo
                    ? 'Nenhum par passou nos filtros na última varredura. O mercado está parado ou caindo.'
                    : 'Sem varredura ainda.'
            }}
        </div>

        <ol v-else class="divide-y rounded-2xl border bg-card">
            <li
                v-for="o in explorer.opportunities"
                :key="o.id"
                class="grid gap-3 p-4 md:grid-cols-[3rem_minmax(0,1fr)_auto] md:items-center"
            >
                <div class="flex items-center gap-3 md:flex-col md:gap-0">
                    <span class="text-2xl font-semibold tabular-nums">{{
                        number(o.score, 0)
                    }}</span>
                    <span class="text-xs text-muted-foreground">nota</span>
                </div>

                <div class="min-w-0 space-y-1.5">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium">{{ o.symbol }}</span>
                        <span class="text-sm tabular-nums">{{
                            price(o.price)
                        }}</span>
                        <span class="text-sm text-emerald-500 tabular-nums"
                            >+{{ number(o.change_24h_pct) }}% 24 h</span
                        >
                        <Badge v-if="o.breakout" variant="secondary"
                            >Rompeu a máxima</Badge
                        >
                        <Badge
                            v-if="o.status === 'registered'"
                            variant="outline"
                            >Cadastrado</Badge
                        >
                        <Badge
                            v-else-if="o.status === 'discovered'"
                            variant="outline"
                            >Achado</Badge
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ o.ai_note ?? o.reasons.join(' ') }}
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        Volume {{ volume(o.quote_volume) }} USDT, spread
                        {{ number(o.spread_pct, 2) }}%<template
                            v-if="o.volume_accel !== null"
                            >, volume da hora
                            {{ number(o.volume_accel) }}x</template
                        ><template v-if="o.extension_atr !== null"
                            >, {{ number(o.extension_atr) }} ATR da
                            média</template
                        >
                    </p>
                </div>

                <div class="flex flex-wrap gap-2 md:justify-end">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="portfolioId === null || busy !== null"
                        @click="post('track', o)"
                    >
                        {{
                            busy === `track:${o.id}`
                                ? 'Acompanhando...'
                                : 'Acompanhar'
                        }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="
                            portfolioId === null ||
                            busy !== null ||
                            !selected?.running
                        "
                        :title="
                            sentRecently(o)
                                ? `Já foi aos agentes nas últimas ${explorer.swarm_cooldown_hours} h.`
                                : undefined
                        "
                        @click="post('swarm', o)"
                    >
                        {{
                            busy === `swarm:${o.id}`
                                ? 'Enviando...'
                                : sentRecently(o)
                                  ? 'Mandar de novo'
                                  : 'Mandar aos agentes'
                        }}
                    </Button>
                </div>
            </li>
        </ol>
    </section>
</template>
