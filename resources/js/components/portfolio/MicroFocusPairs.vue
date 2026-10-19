<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { computed } from 'vue';
import type { MicroFocusRow } from '@/types';

const props = defineProps<{
    focus: MicroFocusRow[];
}>();

type State = {
    key: 'ready' | 'blocked' | 'near' | 'waiting';
    label: string;
    detail: string | null;
};

// Cada par vira um card: estado, volatilidade relativa e as quatro condições do motor.
const maxAtr = computed(() =>
    Math.max(...props.focus.map((row) => row.atr_pct), 0),
);

const cards = computed(() =>
    props.focus.map((row) => {
        const checks = row.checks ?? [];
        const failing = checks.filter((check) => !check.ok).length;

        return {
            ...row,
            checks,
            state: state(row, failing),
            share: maxAtr.value > 0 ? (row.atr_pct / maxAtr.value) * 100 : 0,
        };
    }),
);

function state(row: MicroFocusRow, failing: number): State {
    if (row.entry && row.blocked) {
        return { key: 'blocked', label: 'Bloqueada', detail: row.blocked };
    }

    if (row.entry) {
        return { key: 'ready', label: 'Pronto para entrar', detail: null };
    }

    if (failing === 1) {
        return { key: 'near', label: 'Falta 1 condição', detail: null };
    }

    return {
        key: 'waiting',
        label: failing > 1 ? `Faltam ${failing} condições` : 'Fora do ponto',
        detail: null,
    };
}

// Preços de cripto vão de 0,0001 a 100 mil: até 8 casas, sem zeros sobrando.
function price(value: number, digits?: number) {
    return value.toLocaleString(
        'pt-BR',
        digits
            ? { maximumSignificantDigits: digits }
            : { maximumFractionDigits: 8 },
    );
}

const stateClass: Record<State['key'], string> = {
    ready: 'border-up/40 bg-up/5',
    blocked: 'border-destructive/40 bg-destructive/5',
    near: 'border-amber-500/30',
    waiting: '',
};

const dotClass: Record<State['key'], string> = {
    ready: 'bg-up',
    blocked: 'bg-destructive',
    near: 'bg-amber-500',
    waiting: 'bg-muted-foreground/40',
};
</script>

<template>
    <div class="@container flex flex-col gap-3" data-test="micro-focus">
        <div class="flex items-baseline justify-between gap-2">
            <h4 class="text-sm font-medium">Pares em foco (mais voláteis)</h4>
            <span class="text-xs text-muted-foreground">
                Atualiza a cada minuto
            </span>
        </div>

        <TransitionGroup
            tag="ul"
            move-class="transition-transform duration-500 ease-out"
            class="grid grid-cols-1 gap-3 @md:grid-cols-2 @4xl:grid-cols-3"
        >
            <li
                v-for="card in cards"
                :key="card.symbol"
                class="flex flex-col gap-3 rounded-xl border p-4 transition-colors duration-300"
                :class="stateClass[card.state.key]"
                :data-test="`micro-focus-${card.symbol}`"
                :data-state="card.state.key"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="font-medium tracking-tight">
                        {{ card.symbol }}
                    </span>
                    <span
                        class="flex shrink-0 items-center gap-1.5 text-xs whitespace-nowrap text-muted-foreground"
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :class="dotClass[card.state.key]"
                        />
                        {{ card.state.label }}
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col">
                        <span
                            class="text-xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ card.atr_pct.toFixed(3) }}%
                        </span>
                        <span class="text-xs text-muted-foreground">
                            faixa média por minuto
                        </span>
                    </div>
                    <div class="flex flex-col">
                        <span
                            class="text-xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ card.reach_pct.toFixed(2) }}%
                        </span>
                        <span class="text-xs text-muted-foreground">
                            alcance em 60 min
                        </span>
                    </div>
                </div>

                <div
                    class="h-1 overflow-hidden rounded-full bg-muted"
                    :title="`Volatilidade relativa aos pares em foco: ${Math.round(card.share)}%`"
                >
                    <div
                        class="h-full rounded-full bg-foreground/60 transition-[width] duration-700 ease-out"
                        :style="{ width: `${card.share}%` }"
                    />
                </div>

                <ul v-if="card.checks.length" class="grid gap-1.5 text-sm">
                    <li
                        v-for="check in card.checks"
                        :key="check.key"
                        class="flex items-start gap-2"
                        :title="check.detail"
                        :data-test="`micro-focus-${card.symbol}-${check.key}`"
                        :data-ok="check.ok"
                    >
                        <Check
                            v-if="check.ok"
                            class="mt-0.5 size-3.5 shrink-0 text-up"
                        />
                        <X
                            v-else
                            class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                        />
                        <span class="flex flex-col">
                            <span
                                :class="
                                    check.ok
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ check.label }}
                            </span>
                            <span
                                v-if="!check.ok"
                                class="text-xs text-muted-foreground/80 tabular-nums"
                            >
                                {{ check.detail }}
                            </span>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    {{ card.reason }}
                </p>

                <div
                    v-if="card.suggestion"
                    class="flex items-baseline justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2"
                    :data-test="`micro-focus-${card.symbol}-suggestion`"
                >
                    <span class="flex flex-col">
                        <span
                            class="text-xs whitespace-nowrap text-muted-foreground"
                        >
                            {{
                                card.suggestion.mode === 'limit'
                                    ? 'Compra limitada'
                                    : 'Preço sugerido'
                            }}
                        </span>
                        <span
                            class="text-base font-semibold tracking-tight tabular-nums"
                        >
                            {{ price(card.suggestion.price) }}
                        </span>
                    </span>
                    <span
                        class="text-right text-xs text-muted-foreground tabular-nums"
                    >
                        {{ card.suggestion.discount_pct.toFixed(2) }}% abaixo de
                        {{ price(card.suggestion.reference, 6) }}
                        <template v-if="card.suggestion.mode === 'limit'">
                            <br />vale {{ card.suggestion.seconds }} s
                        </template>
                        <template v-else><br />compra a mercado</template>
                    </span>
                </div>

                <p v-if="card.state.detail" class="text-xs text-destructive">
                    {{ card.state.detail }}
                </p>
                <p v-else-if="card.entry" class="text-xs text-muted-foreground">
                    {{ card.reason }}
                </p>
            </li>
        </TransitionGroup>
    </div>
</template>
