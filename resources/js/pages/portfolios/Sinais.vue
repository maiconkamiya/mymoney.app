<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import SignalCard from '@/components/portfolio/SignalCard.vue';
import { stageLabels } from '@/lib/trading';
import { index, show, signals as portfolioSignals } from '@/routes/portfolios';
import type { SignalRow } from '@/types';

const props = defineProps<{
    portfolio: { id: number; name: string; market: string };
    signals: { data: SignalRow[] };
    filters: { ativo: string; resultado: string };
    symbols: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

const ativo = ref(props.filters.ativo);
const resultado = ref(props.filters.resultado);

function filter() {
    router.get(
        portfolioSignals(props.portfolio.id),
        {
            ativo: ativo.value || undefined,
            resultado: resultado.value || undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <Head :title="`Sinais de ${portfolio.name}`" />

    <div
        class="mx-auto flex w-full max-w-[1100px] flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <div class="flex flex-col gap-2">
            <Link
                :href="show(portfolio.id)"
                class="text-sm text-muted-foreground underline underline-offset-4"
            >
                Voltar para {{ portfolio.name }}
            </Link>
            <Heading
                title="Histórico de sinais"
                description="Todos os sinais da carteira, do mais novo para o mais antigo. Abra um card para ver os votos e o cálculo do score."
            />
        </div>

        <div class="flex flex-wrap gap-3">
            <label class="grid gap-1 text-sm">
                <span class="text-muted-foreground">Ativo</span>
                <select v-model="ativo" :class="selectClass" @change="filter">
                    <option value="">Todos</option>
                    <option
                        v-for="symbol in symbols"
                        :key="symbol"
                        :value="symbol"
                    >
                        {{ symbol }}
                    </option>
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="text-muted-foreground">Resultado</span>
                <select
                    v-model="resultado"
                    :class="selectClass"
                    @change="filter"
                >
                    <option value="">Todos</option>
                    <option
                        v-for="(label, stage) in stageLabels"
                        :key="stage"
                        :value="stage"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
        </div>

        <p
            v-if="signals.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            Nenhum sinal encontrado com esses filtros.
        </p>

        <InfiniteScroll data="signals" class="space-y-3">
            <SignalCard
                v-for="signal in signals.data"
                :key="signal.id"
                :signal="signal"
                :market="portfolio.market"
            />
            <template #loading>
                <p class="py-4 text-center text-sm text-muted-foreground">
                    Carregando mais sinais...
                </p>
            </template>
        </InfiniteScroll>
    </div>
</template>
