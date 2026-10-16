<script setup lang="ts">
import {
    echoIsConfigured,
    useConnectionStatus,
    useEcho,
} from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PortfolioAgentActivityController from '@/actions/App/Http/Controllers/PortfolioAgentActivityController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { applyAgentEvent } from '@/lib/agentActivityState';
import { AgentCanvas } from '@/lib/agentCanvas';
import { agentLabels, stageLabels } from '@/lib/trading';
import type { AgentActivity, AgentLiveEvent } from '@/types';

const props = defineProps<{
    portfolioId: number;
    initial: AgentActivity;
}>();

/** Only a safety net: with the socket down, a slow snapshot keeps the card honest. */
const FALLBACK_POLL_MS = 15000;

const activity = ref<AgentActivity>(props.initial);
const failed = ref(false);
const realtime = echoIsConfigured();
const connection = realtime ? useConnectionStatus() : ref('failed');
const connected = computed(() => connection.value === 'connected');
const canvasEl = ref<HTMLCanvasElement | null>(null);
const wrapperEl = ref<HTMLElement | null>(null);

let canvas: AgentCanvas | null = null;
let observer: ResizeObserver | null = null;
let timer: ReturnType<typeof setInterval> | null = null;
let inFlight = false;

const statusLabel = computed(
    () =>
        ({
            live: 'Ao vivo',
            idle: 'Ocioso',
            empty: 'Sem ciclos',
        })[activity.value.status],
);

const summary = computed(() => {
    const signal = activity.value.signal;

    if (!signal) {
        return 'Nenhum ciclo de análise rodou nesta carteira ainda. Quando o primeiro sinal aparecer, os agentes entram em cena aqui.';
    }

    const stage = stageLabels[signal.stage] ?? signal.stage;
    const lead =
        activity.value.status === 'live'
            ? 'Em andamento'
            : 'Último ciclo (parado)';

    return `${lead}: ${signal.symbol}, ${stage.toLowerCase()}${signal.reason ? `. ${signal.reason}` : '.'}`;
});

const rows = computed(() =>
    activity.value.agents.map((agent) => ({
        ...agent,
        label: agentLabels[agent.role] ?? agent.role,
    })),
);

const dot: Record<string, string> = {
    idle: 'bg-muted-foreground/50',
    analyzing: 'bg-pink-500',
    waiting: 'bg-pink-500',
    approved: 'bg-up',
    buy: 'bg-up',
    sell: 'bg-down',
    rejected: 'bg-down',
    neutral: 'bg-muted-foreground',
    veto: 'bg-amber-400',
};

async function refresh() {
    if (inFlight || document.hidden || connected.value) {
        return;
    }

    inFlight = true;

    try {
        const response = await fetch(
            PortfolioAgentActivityController.url(props.portfolioId),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        activity.value = (await response.json()) as AgentActivity;
        canvas?.setActivity(activity.value);
        failed.value = false;
    } catch {
        failed.value = true;
    } finally {
        inFlight = false;
    }
}

function onVisibility() {
    if (document.hidden) {
        return;
    }

    void refresh();
}

function onEvent(event: AgentLiveEvent) {
    activity.value = applyAgentEvent(activity.value, event);
    canvas?.applyEvent(event, activity.value);
}

if (realtime) {
    useEcho<AgentLiveEvent>(
        `portfolios.${props.portfolioId}.agents`,
        '.agent.activity',
        onEvent,
    );
}

/** A snapshot from the safety-net poll: states only, nothing travels. */
watch(connected, (up) => {
    if (up) {
        void refreshOnce();
    }
});

async function refreshOnce() {
    try {
        const response = await fetch(
            PortfolioAgentActivityController.url(props.portfolioId),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (response.ok) {
            activity.value = (await response.json()) as AgentActivity;
            canvas?.setActivity(activity.value);
        }
    } catch {
        failed.value = true;
    }
}

onMounted(() => {
    if (canvasEl.value) {
        canvas = new AgentCanvas(canvasEl.value);
        canvas.resize();
        canvas.setActivity(activity.value);
    }

    if (wrapperEl.value) {
        observer = new ResizeObserver(() => canvas?.resize());
        observer.observe(wrapperEl.value);
    }

    timer = setInterval(refresh, FALLBACK_POLL_MS);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    canvas?.stop();
    observer?.disconnect();

    if (timer) {
        clearInterval(timer);
    }

    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <Heading
                variant="small"
                title="Agentes em ação"
                description="Quem está lendo, analisando, votando e contestando no ciclo mais recente da carteira."
            />
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span v-if="!connected">
                    Tempo real fora do ar, atualizando a cada 15 s.
                </span>
                <span v-else-if="failed"
                    >Sem atualização, tentando de novo.</span
                >
                <Badge
                    :variant="
                        activity.status === 'live' ? 'default' : 'secondary'
                    "
                >
                    {{ statusLabel }}
                </Badge>
            </div>
        </div>

        <div
            ref="wrapperEl"
            class="relative aspect-[4/5] w-full overflow-hidden rounded-2xl border bg-[#07080c] sm:aspect-[16/10] sm:min-h-[340px]"
        >
            <canvas
                ref="canvasEl"
                role="img"
                :aria-label="`Agentes em ação. ${summary} A lista abaixo descreve o que cada agente está fazendo.`"
                class="absolute inset-0 size-full"
            />
        </div>

        <p class="text-sm text-muted-foreground" aria-live="polite">
            {{ summary }}
        </p>

        <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <li
                v-for="agent in rows"
                :key="agent.role"
                class="rounded-xl border bg-card p-3 text-sm"
            >
                <p class="flex items-center gap-2 font-medium">
                    <span
                        class="size-2 rounded-full"
                        :class="dot[agent.state] ?? dot.idle"
                    />
                    {{ agent.label }}
                </p>
                <p class="mt-1 text-muted-foreground">{{ agent.detail }}</p>
            </li>
        </ul>
    </section>
</template>
