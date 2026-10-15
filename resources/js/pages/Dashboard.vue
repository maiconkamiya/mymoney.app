<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { actionLabels, marketLabels, money } from '@/lib/trading';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/portfolios';
import type { PendingSignal, PortfolioSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

defineProps<{
    halted: boolean;
    portfolios: PortfolioSummary[];
    pending_signals: PendingSignal[];
}>();
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-8 p-4">
        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border p-4"
            :class="
                halted
                    ? 'border-destructive/50 bg-destructive/10'
                    : 'border-sidebar-border/70'
            "
        >
            <div>
                <p class="font-medium">
                    {{
                        halted ? 'Parada geral acionada' : 'Negociação liberada'
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        halted
                            ? 'Nenhuma ordem é enviada até você retomar.'
                            : 'Os agentes analisam e a guarda de risco confere cada ordem.'
                    }}
                </p>
            </div>
            <Link
                :href="index()"
                class="text-sm underline-offset-4 hover:underline"
            >
                {{ halted ? 'Retomar em Carteiras' : 'Gerenciar em Carteiras' }}
            </Link>
        </div>

        <section>
            <Heading
                title="Aguardando sua confirmação"
                description="Sinais que os agentes aprovaram e que só viram ordem depois da sua decisão."
            />

            <div
                v-if="pending_signals.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nenhum sinal aprovado esperando.
            </div>

            <ul v-else class="divide-y rounded-xl border">
                <li
                    v-for="signal in pending_signals"
                    :key="signal.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                >
                    <span class="flex items-center gap-2">
                        <Badge variant="secondary">
                            {{
                                signal.side
                                    ? actionLabels[signal.side]
                                    : 'Aguardar'
                            }}
                        </Badge>
                        <span class="font-medium">{{ signal.symbol }}</span>
                        <span class="text-muted-foreground">
                            {{ signal.portfolio }}
                        </span>
                    </span>
                    <Link
                        :href="show(signal.portfolio_id)"
                        class="underline-offset-4 hover:underline"
                    >
                        Decidir
                    </Link>
                </li>
            </ul>
        </section>

        <section>
            <Heading
                title="Carteiras em execução"
                description="Patrimônio atual de cada simulação em andamento."
            />

            <div
                v-if="portfolios.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nenhuma carteira em execução.
                <Link :href="index()" class="underline">Criar uma</Link>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-3">
                <Link
                    v-for="portfolio in portfolios"
                    :key="portfolio.id"
                    :href="show(portfolio.id)"
                    class="rounded-xl border p-4 hover:bg-muted/50"
                >
                    <p class="font-medium">{{ portfolio.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ marketLabels[portfolio.market] }} ·
                        {{ portfolio.starts_at }} a {{ portfolio.ends_at }}
                    </p>
                    <p class="mt-2 text-lg tabular-nums">
                        {{ money(portfolio.equity, portfolio.market) }}
                    </p>
                </Link>
            </div>
        </section>
    </div>
</template>
